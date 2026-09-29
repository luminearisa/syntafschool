<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\AssessmentResultStatus;
use App\Modules\LMS\Domain\Enums\RubricAssessmentStatus;
use App\Modules\LMS\Domain\Enums\RubricGradingMethod;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsGradeItem;
use App\Modules\LMS\Models\LmsRubric;
use App\Modules\LMS\Models\LmsRubricAssessment;
use App\Modules\LMS\Models\LmsRubricAssessmentScore;
use App\Modules\LMS\Models\LmsRubricCriterion;
use App\Modules\LMS\Models\LmsRubricLevel;
use Illuminate\Support\Facades\DB;

/**
 * Penilaian memakai rubric (§24, §58).
 *
 * Guru memilih level per kriteria, sistem menghitung total, lalu
 * menormalkannya ke nilai maksimum grade item — nilainya masuk ke gradebook
 * lewat `AssessmentResultService` sehingga tidak ada sumber nilai kedua (§61).
 */
class RubricAssessmentService
{
    use LogsLmsAudit;

    public function __construct(
        private readonly AssessmentResultService $results,
        private readonly GradeItemService $items,
    ) {}

    /**
     * Mulai/ambil penilaian rubric kosong untuk seorang siswa.
     */
    public function start(LmsRubric $rubric, int $userId, ?LmsGradeItem $gradeItem = null, ?LmsActivity $activity = null, ?LmsActor $actor = null): LmsRubricAssessment
    {
        $existing = LmsRubricAssessment::query()
            ->where('rubric_id', $rubric->id)
            ->where('user_id', $userId)
            ->when($gradeItem !== null, fn ($query) => $query->where('grade_item_id', $gradeItem->id))
            ->when($gradeItem === null, fn ($query) => $query->whereNull('grade_item_id'))
            ->first();

        if ($existing !== null) {
            return $existing->load('scores.level', 'scores.criterion');
        }

        return LmsRubricAssessment::query()->create([
            'rubric_id' => $rubric->id,
            'grade_item_id' => $gradeItem?->id,
            'activity_id' => $activity?->id ?? $gradeItem?->activity_id,
            'user_id' => $userId,
            'status' => RubricAssessmentStatus::DRAFT,
            'maximum_score' => $rubric->computedMaximum(),
            'assessed_by' => $actor?->id,
        ]);
    }

    /**
     * Simpan pilihan level per kriteria, hitung total, dan (opsional) kirim ke
     * gradebook (§24, §61).
     *
     * @param  array<int, array{level_id?: int|null, score?: float|null, comment?: string|null}>  $selections  criterion_id => pilihan
     * @return array<string, mixed>
     */
    public function assess(
        LmsRubricAssessment $assessment,
        array $selections,
        LmsActor $actor,
        bool $release = false,
        ?string $feedback = null,
    ): array {
        if (! $actor->can('lms.rubric.assess')) {
            LmsFault::forbidden('Anda tidak memiliki izin menilai dengan rubric.');
        }

        $rubric = $assessment->rubric()->firstOrFail()->load('criteria.levels');

        if ($rubric->status->value === 'ARCHIVED') {
            LmsFault::invalid('Rubric ini sudah diarsipkan dan tidak dapat dipakai menilai.', 'rubric');
        }

        $result = DB::transaction(function () use ($assessment, $rubric, $selections, $actor, $release, $feedback): array {
            $total = 0.0;
            $maximum = 0.0;
            $breakdown = [];

            foreach ($rubric->criteria as $criterion) {
                $selection = $selections[$criterion->id] ?? $selections[(string) $criterion->id] ?? null;

                if ($selection === null) {
                    LmsFault::invalid(sprintf('Kriteria "%s" belum dinilai.', $criterion->name), 'selections');
                }

                $level = null;

                if (! empty($selection['level_id'])) {
                    $level = LmsRubricLevel::query()
                        ->where('criterion_id', $criterion->id)
                        ->whereKey((int) $selection['level_id'])
                        ->first();

                    if ($level === null) {
                        LmsFault::invalid(sprintf('Level yang dipilih tidak termasuk kriteria "%s".', $criterion->name), 'selections');
                    }
                }

                $score = $selection['score'] ?? $level?->score ?? 0.0;
                $score = max((float) $score, 0.0);
                $criterionMaximum = $criterion->maximumScore();
                $weight = max((float) $criterion->weight, 0.0) ?: 1.0;

                if ($score > $criterionMaximum + 0.001) {
                    LmsFault::invalid(sprintf('Skor kriteria "%s" melebihi maksimum (%s).', $criterion->name, $criterionMaximum), 'selections');
                }

                LmsRubricAssessmentScore::query()->updateOrCreate(
                    ['rubric_assessment_id' => $assessment->id, 'criterion_id' => $criterion->id],
                    [
                        'level_id' => $level?->id,
                        'score' => $score,
                        'comment' => $selection['comment'] ?? null,
                    ],
                );

                $total += $score * ($rubric->grading_method === RubricGradingMethod::WEIGHTED ? $weight : 1.0);
                $maximum += $criterionMaximum * ($rubric->grading_method === RubricGradingMethod::WEIGHTED ? $weight : 1.0);

                $breakdown[] = [
                    'criterion_id' => (int) $criterion->id,
                    'criterion' => $criterion->name,
                    'level' => $level?->name,
                    'level_description' => $level?->description,
                    'score' => round($score, 2),
                    'maximum' => round($criterionMaximum, 2),
                    'weight' => (float) $criterion->weight,
                    'comment' => $selection['comment'] ?? null,
                ];
            }

            $maximum = $maximum > 0 ? $maximum : max((float) $rubric->maximum_score, 0.01);

            $assessment->fill([
                'status' => $release ? RubricAssessmentStatus::RELEASED : RubricAssessmentStatus::COMPLETED,
                'total_score' => round($total, 2),
                'maximum_score' => round($maximum, 2),
                'normalized_score' => round($total / $maximum * 100, 4),
                'feedback' => $feedback ?? $assessment->feedback,
                'assessed_by' => $actor->id,
                'assessed_at' => now(),
            ])->save();

            $item = $assessment->grade_item_id === null
                ? null
                : LmsGradeItem::query()->find($assessment->grade_item_id);

            $gradeResult = null;

            if ($item !== null) {
                $itemMaximum = max((float) $item->maximum_grade, 0.01);
                $finalScore = round($total / $maximum * $itemMaximum, 2);

                $gradeResult = $this->results->record($item, (int) $assessment->user_id, [
                    'final_score' => $finalScore,
                    'raw_score' => round($total, 2),
                    'maximum_score' => $itemMaximum,
                    'status' => $release ? AssessmentResultStatus::RELEASED : AssessmentResultStatus::GRADED,
                    'feedback' => $feedback,
                    'feedback_meta' => ['rubric' => $breakdown],
                    'metadata' => [
                        'source' => 'rubric',
                        'rubric_id' => (int) $rubric->id,
                        'rubric_assessment_id' => (int) $assessment->id,
                    ],
                    'graded_at' => now(),
                ], $actor);
            }

            return [
                'assessment' => $assessment->refresh()->load('scores.level', 'scores.criterion'),
                'breakdown' => $breakdown,
                'total' => round($total, 2),
                'maximum' => round($maximum, 2),
                'normalized' => round($total / $maximum * 100, 4),
                'grade_result' => $gradeResult,
            ];
        });

        $this->audit('lms.rubric.assessed', $assessment, [
            'rubric_id' => (int) $assessment->rubric_id,
            'user_id' => (int) $assessment->user_id,
            'total' => $result['total'],
        ]);

        return $result;
    }

    /** Penilaian rubric seorang siswa pada satu grade item (§58). */
    public function forStudent(LmsGradeItem $item, int $userId): ?array
    {
        $assessment = LmsRubricAssessment::query()
            ->where('grade_item_id', $item->id)
            ->where('user_id', $userId)
            ->with('scores.level', 'scores.criterion', 'rubric')
            ->first();

        if ($assessment === null) {
            return null;
        }

        $rubric = $assessment->rubric;

        return [
            'id' => (int) $assessment->id,
            'rubric_id' => (int) $assessment->rubric_id,
            'rubric_name' => $rubric?->name,
            'status' => $assessment->status->value,
            'status_label' => $assessment->status->label(),
            'total_score' => (float) $assessment->total_score,
            'maximum_score' => (float) $assessment->maximum_score,
            'normalized_score' => $assessment->normalized_score === null ? null : (float) $assessment->normalized_score,
            'feedback' => $assessment->feedback,
            'is_released' => $assessment->status === RubricAssessmentStatus::RELEASED,
            'criteria' => $rubric === null ? [] : $rubric->criteria->map(function (LmsRubricCriterion $criterion) use ($assessment): array {
                $score = $assessment->scores->firstWhere('criterion_id', $criterion->id);

                return [
                    'criterion_id' => (int) $criterion->id,
                    'criterion' => $criterion->name,
                    'weight' => (float) $criterion->weight,
                    'maximum' => $criterion->maximumScore(),
                    'score' => $score === null ? null : (float) $score->score,
                    'level' => $score?->level?->name,
                    'comment' => $score?->comment,
                ];
            })->all(),
        ];
    }

    /** Salin rubric ke grade item lalu mulai penilaian (§24, §25). */
    public function attachRubricToItem(LmsGradeItem $item, LmsRubric $rubric, LmsActor $actor): LmsGradeItem
    {
        return app(RubricService::class)->attachToGradeItem($item, $rubric, $actor);
    }
}
