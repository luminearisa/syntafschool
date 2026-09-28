<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\ActivityGradeSource;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsActivityGrade;
use App\Modules\LMS\Models\LmsEnrollment;
use Illuminate\Support\Collection;

/**
 * Catatan nilai aktivitas (§46–47).
 *
 * Nilai di sini adalah "assessment result" milik LMS. Nilai rapor SIAKAD tidak
 * ikut berubah: pengiriman ke ERP hanya lewat `LmsIntegrationService` secara
 * eksplisit (§8, §47).
 */
class ActivityGradeService
{
    use LogsLmsAudit;

    /**
     * Tulis atau perbarui nilai seorang siswa untuk satu aktivitas.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(LmsActivity $activity, int $userId, array $data = []): LmsActivityGrade
    {
        $existing = LmsActivityGrade::query()
            ->where('activity_id', $activity->id)
            ->where('user_id', $userId)
            ->first();

        $grade = $data['grade'] ?? null;
        $maximum = $data['maximum_grade'] ?? $existing?->maximum_grade ?? 100;

        $passed = $data['passed'] ?? null;

        if ($passed === null && $grade !== null && $activity->completion_min_grade !== null) {
            $passed = (float) $grade >= (float) $activity->completion_min_grade;
        }

        $attributes = [
            'course_id' => $activity->course_id,
            'learning_item_id' => $activity->learning_item_id,
            'student_id' => $data['student_id'] ?? $existing?->student_id,
            'enrollment_id' => $data['enrollment_id'] ?? $existing?->enrollment_id,
            'source' => ($data['source'] ?? null) ?: ($existing?->source?->value ?? $this->defaultSource($activity)->value),
            'grade' => $grade,
            'raw_grade' => $data['raw_grade'] ?? $existing?->raw_grade,
            'maximum_grade' => $maximum,
            'original_grade' => $data['original_grade'] ?? $existing?->original_grade,
            'passed' => $passed,
            'feedback' => $data['feedback'] ?? $existing?->feedback,
            'graded_by' => $data['graded_by'] ?? $existing?->graded_by,
            'graded_at' => $data['graded_at'] ?? now(),
            'metadata' => $data['metadata'] ?? $existing?->metadata,
        ];

        $record = LmsActivityGrade::updateOrCreate(
            ['activity_id' => $activity->id, 'user_id' => $userId],
            $attributes,
        );

        $this->audit('LMS_ACTIVITY_GRADED', $activity, [
            'user_id' => $userId,
            'grade' => $record->grade,
            'source' => $record->source?->value,
        ]);

        return $record;
    }

    public function forActivityUser(LmsActivity $activity, int $userId): ?LmsActivityGrade
    {
        return LmsActivityGrade::query()
            ->where('activity_id', $activity->id)
            ->where('user_id', $userId)
            ->first();
    }

    /** @return Collection<int, LmsActivityGrade> */
    public function forActivity(LmsActivity $activity): Collection
    {
        return LmsActivityGrade::query()->where('activity_id', $activity->id)->get();
    }

    /** @return Collection<int, LmsActivityGrade> */
    public function forStudent(int $courseId, int $userId): Collection
    {
        return LmsActivityGrade::query()
            ->where('course_id', $courseId)
            ->where('user_id', $userId)
            ->with('activity')
            ->get();
    }

    /**
     * Ringkasan nilai satu course untuk seorang siswa — bahan Gradebook (§46).
     *
     * @return array{
     *     activities: int, graded: int, average: float|null,
     *     average_percent: float|null, passed: int, failed: int,
     *     records: list<array<string, mixed>>
     * }
     */
    public function courseSummary(int $courseId, int $userId): array
    {
        $records = $this->forStudent($courseId, $userId);

        $percentages = $records
            ->filter(fn (LmsActivityGrade $grade) => $grade->grade !== null && (float) $grade->maximum_grade > 0)
            ->map(fn (LmsActivityGrade $grade) => $grade->percent());

        return [
            'activities' => $records->count(),
            'graded' => $percentages->count(),
            'average' => $records->whereNotNull('grade')->avg(fn (LmsActivityGrade $grade) => (float) $grade->grade),
            'average_percent' => $percentages->isEmpty() ? null : round($percentages->avg(), 2),
            'passed' => $records->where('passed', true)->count(),
            'failed' => $records->where('passed', false)->count(),
            'records' => $records->map(fn (LmsActivityGrade $grade) => [
                'activity_id' => (int) $grade->activity_id,
                'title' => $grade->activity?->title,
                'activity_type' => $grade->activity?->activity_type?->value,
                'source' => $grade->source?->value,
                'grade' => $grade->grade === null ? null : (float) $grade->grade,
                'maximum_grade' => (float) $grade->maximum_grade,
                'percent' => $grade->grade === null ? null : $grade->percent(),
                'passed' => $grade->passed,
                'feedback' => $grade->feedback,
                'graded_at' => $grade->graded_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** Nilai akhir sebuah aktivitas yang dipakai gradebook (0–100). */
    public function normalized(LmsActivityGrade $grade): ?float
    {
        return $grade->grade === null ? null : $grade->percent();
    }

    public function forget(LmsActivity $activity): int
    {
        return LmsActivityGrade::query()->where('activity_id', $activity->id)->delete();
    }

    protected function defaultSource(LmsActivity $activity): ActivityGradeSource
    {
        return match ($activity->activity_type?->value) {
            'ASSIGNMENT' => ActivityGradeSource::ASSIGNMENT,
            'QUIZ' => ActivityGradeSource::QUIZ,
            'FORUM' => ActivityGradeSource::FORUM,
            'LESSON' => ActivityGradeSource::LESSON,
            'CHOICE' => ActivityGradeSource::CHOICE,
            'SURVEY' => ActivityGradeSource::SURVEY,
            default => ActivityGradeSource::MANUAL,
        };
    }
}
