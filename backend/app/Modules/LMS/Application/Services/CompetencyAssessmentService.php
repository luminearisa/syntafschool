<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\CompetencyEvidenceType;
use App\Modules\LMS\Domain\Enums\CompetencyStatus;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsAssessmentResult;
use App\Modules\LMS\Models\LmsCompetency;
use App\Modules\LMS\Models\LmsCompetencyEvidence;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseCompetency;
use App\Modules\LMS\Models\LmsStudentCompetency;
use Illuminate\Support\Facades\DB;

/**
 * Penetapan kompetensi siswa beserta buktinya (§30–§31).
 *
 * Guru menetapkan status; sistem hanya menyimpan dan merangkum. Tidak ada
 * konversi otomatis dari nilai angka ke kompetensi karena keduanya memang
 * konsep berbeda.
 */
class CompetencyAssessmentService
{
    use LogsLmsAudit;

    /** Ambil (buat bila perlu) status kompetensi seorang siswa. */
    public function ensure(LmsCompetency $competency, int $userId, ?LmsCourse $course = null): LmsStudentCompetency
    {
        return LmsStudentCompetency::query()->firstOrCreate(
            [
                'competency_id' => $competency->id,
                'user_id' => $userId,
                'course_id' => $course?->id,
            ],
            [
                'student_id' => $course === null ? null : $this->studentIdFor($course, $userId),
                'status' => CompetencyStatus::NOT_STARTED,
            ],
        );
    }

    /**
     * Tetapkan status kompetensi siswa (§30).
     *
     * @param  array<string, mixed>  $data  note, score, evidence (list)
     */
    public function assess(
        LmsCompetency $competency,
        int $userId,
        CompetencyStatus $status,
        LmsActor $actor,
        ?LmsCourse $course = null,
        array $data = [],
    ): LmsStudentCompetency {
        if (! $actor->can('lms.competency.assess')) {
            LmsFault::forbidden('Anda tidak memiliki izin menilai kompetensi.');
        }

        $record = DB::transaction(function () use ($competency, $userId, $status, $actor, $course, $data): LmsStudentCompetency {
            $record = $this->ensure($competency, $userId, $course);

            $record->fill([
                'status' => $status,
                'note' => $data['note'] ?? $record->note,
                'score' => $data['score'] ?? $record->score,
                'assessed_by' => $actor->id,
                'assessed_at' => now(),
            ])->save();

            foreach ((array) ($data['evidence'] ?? []) as $evidence) {
                $this->recordEvidence(
                    $record,
                    CompetencyEvidenceType::tryFrom((string) ($evidence['evidence_type'] ?? 'MANUAL')) ?? CompetencyEvidenceType::MANUAL,
                    $actor,
                    [
                        'activity_id' => $evidence['activity_id'] ?? null,
                        'grade_item_id' => $evidence['grade_item_id'] ?? null,
                        'assessment_result_id' => $evidence['assessment_result_id'] ?? null,
                        'title' => $evidence['title'] ?? null,
                        'score' => $evidence['score'] ?? null,
                        'note' => $evidence['note'] ?? null,
                    ],
                );
            }

            return $record->refresh();
        });

        $this->audit('lms.competency.assessed', $record, [
            'competency_id' => (int) $competency->id,
            'user_id' => $userId,
            'status' => $status->value,
        ]);

        return $record;
    }

    /**
     * Catat bukti capaian (§31): aktivitas, hasil penilaian, rubric, guru, tanggal.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordEvidence(
        LmsStudentCompetency $record,
        CompetencyEvidenceType $type,
        LmsActor $actor,
        array $data = [],
    ): LmsCompetencyEvidence {
        $evidence = LmsCompetencyEvidence::query()->create([
            'competency_id' => $record->competency_id,
            'student_competency_id' => $record->id,
            'course_id' => $data['course_id'] ?? $record->course_id,
            'activity_id' => $data['activity_id'] ?? null,
            'grade_item_id' => $data['grade_item_id'] ?? null,
            'assessment_result_id' => $data['assessment_result_id'] ?? null,
            'rubric_assessment_id' => $data['rubric_assessment_id'] ?? null,
            'user_id' => $record->user_id,
            'student_id' => $record->student_id,
            'evidence_type' => $type,
            'title' => $data['title'] ?? null,
            'score' => $data['score'] ?? null,
            'note' => $data['note'] ?? null,
            'recorded_by' => $actor->id,
            'recorded_at' => now(),
        ]);

        $this->audit('lms.competency.evidence_recorded', $evidence, ['type' => $type->value]);

        return $evidence;
    }

    /**
     * Bukti dari aktivitas LMS (§31) — memakai nilai yang sudah ada, tidak
     * menciptakan nilai baru.
     */
    public function recordActivityEvidence(LmsStudentCompetency $record, LmsActivity $activity, LmsActor $actor, ?string $note = null): LmsCompetencyEvidence
    {
        $result = LmsAssessmentResult::query()
            ->where('course_id', $activity->course_id)
            ->where('user_id', $record->user_id)
            ->whereIn('grade_item_id', \App\Modules\LMS\Models\LmsGradeItem::query()->where('activity_id', $activity->id)->pluck('id'))
            ->first();

        $rubricAssessment = \App\Modules\LMS\Models\LmsRubricAssessment::query()
            ->where('activity_id', $activity->id)
            ->where('user_id', $record->user_id)
            ->first();

        return $this->recordEvidence($record, CompetencyEvidenceType::ACTIVITY, $actor, [
            'activity_id' => $activity->id,
            'grade_item_id' => $result?->grade_item_id,
            'assessment_result_id' => $result?->id,
            'rubric_assessment_id' => $rubricAssessment?->id,
            'title' => $activity->title,
            'score' => $result?->normalized() === null ? null : round((float) $result->normalized(), 2),
            'note' => $note,
        ]);
    }

    /**
     * Kompetensi seorang siswa pada sebuah course (§30, §32).
     *
     * @return list<array<string, mixed>>
     */
    public function forStudent(LmsCourse $course, int $userId): array
    {
        $mappings = LmsCourseCompetency::query()
            ->where('course_id', $course->id)
            ->with('competency')
            ->get();

        return $mappings->map(function (LmsCourseCompetency $mapping) use ($userId, $course): array {
            $record = LmsStudentCompetency::query()
                ->where('competency_id', $mapping->competency_id)
                ->where('user_id', $userId)
                ->first();

            $evidence = LmsCompetencyEvidence::query()
                ->where('competency_id', $mapping->competency_id)
                ->where('user_id', $userId)
                ->orderByDesc('recorded_at')
                ->get();

            return [
                'competency_id' => (int) $mapping->competency_id,
                'code' => $mapping->competency?->code,
                'name' => $mapping->competency?->name,
                'description' => $mapping->competency?->description,
                'status' => $record?->status->value ?? CompetencyStatus::NOT_STARTED->value,
                'status_label' => $record?->status->label() ?? CompetencyStatus::NOT_STARTED->label(),
                'status_tone' => $record?->status->tone() ?? CompetencyStatus::NOT_STARTED->tone(),
                'note' => $record?->note,
                'assessed_at' => $record?->assessed_at?->toIso8601String(),
                'course_id' => (int) $course->id,
                'evidence' => $evidence->map(fn (LmsCompetencyEvidence $item) => [
                    'id' => (int) $item->id,
                    'evidence_type' => $item->evidence_type->value,
                    'evidence_type_label' => $item->evidence_type->label(),
                    'evidence_type_tone' => $item->evidence_type->tone(),
                    'title' => $item->title,
                    'score' => $item->score === null ? null : (float) $item->score,
                    'note' => $item->note,
                    'activity_id' => $item->activity_id === null ? null : (int) $item->activity_id,
                    'recorded_at' => $item->recorded_at?->toIso8601String(),
                ])->all(),
            ];
        })->all();
    }

    /**
     * Rekap kemajuan kompetensi satu course (§48–§49).
     *
     * @return list<array<string, mixed>>
     */
    public function courseProgress(LmsCourse $course): array
    {
        $userIds = app(GradeStatisticsService::class)->studentIds($course);

        return LmsCourseCompetency::query()
            ->where('course_id', $course->id)
            ->with('competency')
            ->get()
            ->map(function (LmsCourseCompetency $mapping) use ($userIds): array {
                $records = LmsStudentCompetency::query()
                    ->where('competency_id', $mapping->competency_id)
                    ->whereIn('user_id', $userIds)
                    ->get();

                $counts = array_fill_keys(CompetencyStatus::values(), 0);

                foreach ($records as $record) {
                    $counts[$record->status->value] = ($counts[$record->status->value] ?? 0) + 1;
                }

                $counts[CompetencyStatus::NOT_STARTED->value] += max(count($userIds) - $records->count(), 0);
                $achieved = ($counts[CompetencyStatus::COMPETENT->value] ?? 0) + ($counts[CompetencyStatus::MASTERED->value] ?? 0);

                return [
                    'competency_id' => (int) $mapping->competency_id,
                    'code' => $mapping->competency?->code,
                    'name' => $mapping->competency?->name,
                    'student_count' => count($userIds),
                    'assessed_count' => $records->count(),
                    'achieved_count' => $achieved,
                    'achievement_rate' => $records->count() === 0 ? null : round($achieved / max($records->count(), 1) * 100, 2),
                    'breakdown' => $counts,
                ];
            })
            ->all();
    }

    private function studentIdFor(LmsCourse $course, int $userId): ?int
    {
        return $course->enrollmentFor($userId)?->student_id;
    }
}
