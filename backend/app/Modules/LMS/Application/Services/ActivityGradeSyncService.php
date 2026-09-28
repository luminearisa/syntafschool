<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsActivityGrade;
use App\Modules\LMS\Models\LmsCourse;

/**
 * Jembatan nilai LMS ↔ SIAKAD (§8, §47).
 *
 * Nilai aktivitas LMS **tidak pernah** menulis ke nilai akademik secara
 * otomatis. Service ini hanya menyiapkan payload dan — bila admin/guru memang
 * meminta — mengirimkannya lewat `LmsIntegrationService` sehingga jejaknya
 * tercatat.
 */
class ActivityGradeSyncService
{
    use LogsLmsAudit;

    public function __construct(
        private readonly LmsIntegrationService $integration,
        private readonly ActivityGradeService $grades,
    ) {}

    /**
     * Payload nilai untuk modul akademik ERP (tanpa menulis apa pun).
     *
     * @return array<string, mixed>
     */
    public function payload(LmsActivity $activity, int $userId): array
    {
        $grade = $this->grades->forActivityUser($activity, $userId);
        $enrollment = $activity->course?->enrollmentFor($userId);

        return [
            'source' => 'lms',
            'course' => [
                'lms_course_id' => (int) $activity->course_id,
                'academic_course_id' => $activity->course?->academic_course_id,
                'class_id' => $activity->course?->class_id,
                'academic_year_id' => $activity->course?->academic_year_id,
                'semester_id' => $activity->course?->semester_id,
            ],
            'activity' => [
                'id' => (int) $activity->id,
                'title' => (string) $activity->title,
                'activity_type' => $activity->activity_type?->value,
                'maximum_grade' => $grade === null ? 100 : (float) $grade->maximum_grade,
            ],
            'student' => [
                'user_id' => $userId,
                'student_id' => $grade?->student_id ?? $enrollment?->student_id,
                'external_student_id' => $enrollment?->student_id,
            ],
            'result' => [
                'grade' => $grade?->grade === null ? null : (float) $grade->grade,
                'percent' => $grade?->percent(),
                'passed' => $grade?->passed,
                'feedback' => $grade?->feedback,
                'graded_at' => $grade?->graded_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Kirim nilai satu aktivitas ke SIAKAD — hanya atas permintaan eksplisit.
     *
     * @return array<string, mixed>
     */
    public function push(LmsActivity $activity, int $userId, LmsActor $actor): array
    {
        $payload = $this->payload($activity, $userId);
        $result = $this->integration->publishActivityGrade($payload);

        $this->audit('LMS_GRADE_PUSHED_TO_ACADEMIC', $activity, [
            'user_id' => $userId,
            'accepted' => $result['accepted'] ?? false,
            'actor_id' => $actor->id,
        ]);

        return $result;
    }

    /** Ringkasan nilai course (dipakai halaman nilai & Phase 3 gradebook). */
    public function courseSummary(LmsCourse $course, int $userId): array
    {
        return $this->grades->courseSummary((int) $course->id, $userId);
    }

    /** @return array<string, mixed> */
    public function gradePayload(LmsActivityGrade $grade): array
    {
        return [
            'activity_id' => (int) $grade->activity_id,
            'grade' => $grade->grade === null ? null : (float) $grade->grade,
            'original_grade' => $grade->original_grade === null ? null : (float) $grade->original_grade,
            'maximum_grade' => (float) $grade->maximum_grade,
            'percent' => $grade->percent(),
            'passed' => $grade->passed,
            'feedback' => $grade->feedback,
            'source' => $grade->source?->value,
            'graded_at' => $grade->graded_at?->toIso8601String(),
        ];
    }
}
