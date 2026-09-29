<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\OutcomeAchievement;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsAssessmentResult;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsGradeItem;
use App\Modules\LMS\Models\LmsLearningOutcome;
use App\Modules\LMS\Models\LmsStudentOutcome;
use Illuminate\Support\Facades\DB;

/**
 * Capaian learning outcome (§28).
 *
 * Capaian dihitung dari aktivitas yang **dipetakan** ke outcome tersebut,
 * memakai ambang batas yang dapat dikonfigurasi — bukan kesimpulan otomatis
 * tentang kemampuan anak (§47).
 */
class OutcomeAssessmentService
{
    use LogsLmsAudit;

    /**
     * Hitung ulang capaian satu outcome untuk sekumpulan siswa.
     *
     * @param  list<int>  $userIds
     * @return list<array<string, mixed>>
     */
    public function recalculate(LmsLearningOutcome $outcome, array $userIds, ?LmsActor $actor = null): array
    {
        $outcome->loadMissing('mappings.activity', 'course');

        $activityIds = $outcome->mappings->pluck('activity_id')->map(fn ($id) => (int) $id)->all();
        $itemIds = $activityIds === []
            ? []
            : LmsGradeItem::query()->whereIn('activity_id', $activityIds)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $out = [];

        foreach ($userIds as $userId) {
            $out[] = $this->recalculateForStudent($outcome, (int) $userId, $activityIds, $itemIds, $actor);
        }

        return $out;
    }

    /**
     * Hitung capaian outcome untuk seluruh siswa aktif course.
     *
     * @return list<array<string, mixed>>
     */
    public function recalculateCourse(LmsCourse $course, ?LmsActor $actor = null): array
    {
        $userIds = app(GradeStatisticsService::class)->studentIds($course);
        $out = [];

        foreach ($course->learningOutcomes()->get() as $outcome) {
            foreach ($this->recalculate($outcome, $userIds, $actor) as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Capaian satu siswa pada semua outcome sebuah course (§28, §32).
     *
     * @return list<array<string, mixed>>
     */
    public function forStudent(LmsCourse $course, int $userId, bool $recalculate = false): array
    {
        $outcomes = $course->learningOutcomes()->orderBy('sort_order')->get();

        if ($recalculate) {
            $this->recalculate($outcomes->first() ?? new LmsLearningOutcome(), [$userId], null);
        }

        return $outcomes->map(function (LmsLearningOutcome $outcome) use ($userId, $course): array {
            $record = LmsStudentOutcome::query()
                ->where('learning_outcome_id', $outcome->id)
                ->where('user_id', $userId)
                ->first();

            return [
                'learning_outcome_id' => (int) $outcome->id,
                'code' => $outcome->code,
                'statement' => $outcome->statement,
                'status' => $record?->status->value ?? OutcomeAchievement::NOT_ASSESSED->value,
                'status_label' => $record?->status->label() ?? OutcomeAchievement::NOT_ASSESSED->label(),
                'status_tone' => $record?->status->tone() ?? OutcomeAchievement::NOT_ASSESSED->tone(),
                'score' => $record?->score === null ? null : (float) $record->score,
                'evidence_count' => (int) ($record?->evidence_count ?? 0),
                'calculation' => $record?->calculation,
                'thresholds' => $outcome->thresholds(),
                'activities' => $outcome->mappings()->with('activity')->get()->map(fn ($mapping) => [
                    'activity_id' => (int) $mapping->activity_id,
                    'title' => $mapping->activity?->title,
                ])->all(),
                'course_id' => (int) $course->id,
            ];
        })->all();
    }

    /**
     * Rekap capaian seluruh kelas untuk satu course (§48).
     *
     * @return list<array<string, mixed>>
     */
    public function courseAchievement(LmsCourse $course): array
    {
        $userIds = app(GradeStatisticsService::class)->studentIds($course);
        $rows = [];

        foreach ($course->learningOutcomes()->with('mappings')->orderBy('sort_order')->get() as $outcome) {
            $counts = array_fill_keys(OutcomeAchievement::values(), 0);

            foreach ($userIds as $userId) {
                $record = LmsStudentOutcome::query()
                    ->where('learning_outcome_id', $outcome->id)
                    ->where('user_id', $userId)
                    ->first();

                $status = $record?->status ?? OutcomeAchievement::NOT_ASSESSED;
                $counts[$status->value] = ($counts[$status->value] ?? 0) + 1;
            }

            $assessed = count($userIds) - ($counts[OutcomeAchievement::NOT_ASSESSED->value] ?? 0);
            $achieved = ($counts[OutcomeAchievement::ACHIEVED->value] ?? 0) + ($counts[OutcomeAchievement::MASTERED->value] ?? 0);

            $rows[] = [
                'learning_outcome_id' => (int) $outcome->id,
                'code' => $outcome->code,
                'statement' => $outcome->statement,
                'mapped_activities' => $outcome->mappings->count(),
                'student_count' => count($userIds),
                'assessed_count' => $assessed,
                'achieved_count' => $achieved,
                'achievement_rate' => $assessed === 0 ? null : round($achieved / $assessed * 100, 2),
                'breakdown' => $counts,
            ];
        }

        return $rows;
    }

    /** @param list<int> $activityIds @param list<int> $itemIds */
    private function recalculateForStudent(
        LmsLearningOutcome $outcome,
        int $userId,
        array $activityIds,
        array $itemIds,
        ?LmsActor $actor,
    ): array {
        $results = $itemIds === []
            ? collect()
            : LmsAssessmentResult::query()
                ->whereIn('grade_item_id', $itemIds)
                ->where('user_id', $userId)
                ->get()
                ->keyBy('grade_item_id');

        $items = $itemIds === []
            ? collect()
            : LmsGradeItem::query()->whereIn('id', $itemIds)->get();

        $values = [];
        $evidence = [];

        foreach ($items as $item) {
            $result = $results->get($item->id);
            $normalized = $result?->normalized();

            $evidence[] = [
                'grade_item_id' => (int) $item->id,
                'activity_id' => $item->activity_id === null ? null : (int) $item->activity_id,
                'name' => $item->name,
                'score' => $normalized === null ? null : round($normalized, 2),
                'status' => $result?->status->value,
            ];

            if ($normalized !== null && ($result?->isExempt() ?? false) === false) {
                $values[] = $normalized;
            }
        }

        $score = $values === [] ? null : round(array_sum($values) / count($values), 2);
        $status = OutcomeAchievement::fromScore($score, $outcome->thresholds());

        $record = DB::transaction(function () use ($outcome, $userId, $score, $status, $evidence, $actor): LmsStudentOutcome {
            return LmsStudentOutcome::query()->updateOrCreate(
                ['learning_outcome_id' => $outcome->id, 'user_id' => $userId],
                [
                    'course_id' => $outcome->course_id,
                    'student_id' => $this->studentIdFor((int) $outcome->course_id, $userId),
                    'status' => $status,
                    'score' => $score,
                    'evidence_count' => count(array_filter($evidence, fn (array $row) => $row['score'] !== null)),
                    'calculation' => ['evidence' => $evidence, 'thresholds' => $outcome->thresholds()],
                    'assessed_by' => $actor?->id ?? $outcome->created_by,
                    'assessed_at' => now(),
                ],
            );
        });

        return [
            'learning_outcome_id' => (int) $outcome->id,
            'code' => $outcome->code,
            'user_id' => $userId,
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_tone' => $status->tone(),
            'score' => $score,
            'evidence_count' => (int) $record->evidence_count,
            'evidence' => $evidence,
        ];
    }

    private function studentIdFor(int $courseId, int $userId): ?int
    {
        return \App\Modules\LMS\Models\LmsEnrollment::query()
            ->where('course_id', $courseId)
            ->where('user_id', $userId)
            ->value('student_id');
    }
}
