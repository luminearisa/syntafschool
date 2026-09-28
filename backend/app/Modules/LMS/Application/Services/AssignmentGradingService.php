<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\AssignmentSubmissionStatus;
use App\Modules\LMS\Domain\Enums\GradingType;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsAssignment;
use App\Modules\LMS\Models\LmsAssignmentSubmission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penilaian tugas (§7–§9).
 *
 * Nilai asli guru tidak pernah ditimpa: penalti keterlambatan disimpan pada
 * `original_grade` + `late_penalty_percent`, nilai akhir pada `grade` (§9).
 * Modul LMS hanya menghasilkan catatan nilai aktivitas — bukan perubahan nilai
 * rapor SIAKAD (§8).
 */
class AssignmentGradingService
{
    use LogsLmsAudit;

    public function __construct(
        private readonly AssignmentSubmissionService $submissions,
        private readonly ActivityCompletionService $completion,
        private readonly ActivityGradeService $grades,
    ) {}

    /**
     * Beri nilai + umpan balik (§7).
     *
     * @param  array<string, mixed>  $data
     */
    public function grade(LmsAssignmentSubmission $submission, array $data, LmsActor $actor): LmsAssignmentSubmission
    {
        $assignment = $submission->assignment;
        $activity = $assignment?->activity;

        if ($assignment === null || $activity === null) {
            throw ValidationException::withMessages(['submission' => 'Submission tidak memiliki tugas yang sah.']);
        }

        if ($submission->submitted_at === null) {
            throw ValidationException::withMessages(['submission' => 'Submission belum dikirim oleh siswa.']);
        }

        $maximum = (float) $assignment->maximum_grade;
        $original = (float) ($data['grade'] ?? 0);

        if ($original < 0 || $original > $maximum) {
            throw ValidationException::withMessages([
                'grade' => 'Nilai harus berada di antara 0 dan '.$maximum.'.',
            ]);
        }

        $penalty = $submission->late ? $this->latePenaltyPercent($assignment, $submission->submitted_at) : 0.0;
        $adjusted = $this->applyPenalty($original, $penalty);

        $passed = $assignment->passing_grade === null
            ? null
            : $adjusted >= (float) $assignment->passing_grade;

        if (($assignment->grading_type ?? GradingType::POINTS) === GradingType::PASS_FAIL) {
            $passed ??= $adjusted >= $maximum / 2;
            $adjusted = $passed ? $maximum : 0.0;
            $original = $passed ? $maximum : 0.0;
        }

        return DB::transaction(function () use ($submission, $activity, $assignment, $data, $actor, $original, $adjusted, $penalty, $passed): LmsAssignmentSubmission {
            $submission->forceFill([
                'status' => AssignmentSubmissionStatus::GRADED->value,
                'grade' => $adjusted,
                'original_grade' => $original,
                'late_penalty_percent' => $penalty,
                'feedback' => $data['feedback'] ?? $submission->feedback,
                'passed' => $passed,
                'graded_at' => now(),
                'grader_id' => $actor->id,
            ])->save();

            if (isset($data['feedback_file']) && $data['feedback_file'] instanceof UploadedFile) {
                $this->submissions->storeFeedbackFile($submission, $data['feedback_file'], $actor);
            }

            // Nilai masuk ke struktur nilai LMS (§46–47).
            $this->grades->record($activity, $submission->user_id, [
                'grade' => $adjusted,
                'original_grade' => $original,
                'maximum_grade' => (float) $assignment->maximum_grade,
                'passed' => $passed,
                'feedback' => $submission->feedback,
                'graded_by' => $actor->id,
                'student_id' => $submission->student_id,
                'source' => 'ASSIGNMENT',
            ]);

            // Penyelesaian tetap tercatat dengan nilai terbaru (§40).
            $enrollment = $activity->course?->enrollmentFor($submission->user_id);

            if ($enrollment !== null) {
                $this->completion->complete($activity, $enrollment, $adjusted, 'assignment_graded', $actor->id, [
                    'submission_id' => $submission->id,
                ]);
            }

            $this->audit('LMS_SUBMISSION_GRADED', $activity, [
                'submission_id' => $submission->id,
                'grade' => $adjusted,
                'original_grade' => $original,
                'late_penalty_percent' => $penalty,
                'grader_id' => $actor->id,
            ]);

            return $submission->fresh(['files']);
        });
    }

    /** Kembalikan submission tanpa nilai agar siswa memperbaiki (§5). */
    public function returnToStudent(LmsAssignmentSubmission $submission, ?string $reason, LmsActor $actor): LmsAssignmentSubmission
    {
        $activity = $submission->assignment?->activity;

        $submission->forceFill([
            'status' => AssignmentSubmissionStatus::RETURNED->value,
            'returned_at' => now(),
            'return_reason' => $reason,
            'grader_id' => $actor->id,
        ])->save();

        $this->audit('LMS_SUBMISSION_RETURNED', $activity, [
            'submission_id' => $submission->id,
            'reason' => $reason,
        ]);

        return $submission->fresh(['files']);
    }

    /** Tolak submission (mis. melewati cutoff) — riwayat tetap tersimpan. */
    public function reject(LmsAssignmentSubmission $submission, ?string $reason, LmsActor $actor): LmsAssignmentSubmission
    {
        $activity = $submission->assignment?->activity;

        $submission->forceFill([
            'status' => AssignmentSubmissionStatus::REJECTED->value,
            'return_reason' => $reason,
            'grader_id' => $actor->id,
        ])->save();

        $this->audit('LMS_SUBMISSION_REJECTED', $activity, [
            'submission_id' => $submission->id,
            'reason' => $reason,
        ]);

        return $submission->fresh(['files']);
    }

    /**
     * Penilaian massal (§11) — nilai sederhana tanpa umpan balik per siswa.
     *
     * @param  list<array{submission_id: int, grade: float, feedback?: string|null}>  $entries
     * @return array{graded: int, failed: list<array<string, mixed>>}
     */
    public function bulkGrade(array $entries, LmsActor $actor): array
    {
        $graded = 0;
        $failed = [];

        foreach ($entries as $entry) {
            $submission = LmsAssignmentSubmission::query()->find($entry['submission_id'] ?? 0);

            if ($submission === null) {
                $failed[] = ['submission_id' => $entry['submission_id'] ?? null, 'reason' => 'Submission tidak ditemukan.'];
                continue;
            }

            try {
                $this->grade($submission, [
                    'grade' => $entry['grade'] ?? 0,
                    'feedback' => $entry['feedback'] ?? null,
                ], $actor);

                $graded++;
            } catch (ValidationException $exception) {
                $failed[] = [
                    'submission_id' => $submission->id,
                    'reason' => collect($exception->errors())->flatten()->first(),
                ];
            }
        }

        return ['graded' => $graded, 'failed' => $failed];
    }

    /**
     * Persentase penalti keterlambatan pada saat pengumpulan (§9).
     *
     * Bila `late_penalty_days_step` diisi, penalti berlaku kelipatan per N hari
     * (mis. 10% per 2 hari) dan dibatasi `late_penalty_max_percent`.
     */
    public function latePenaltyPercent(LmsAssignment $assignment, ?Carbon $submittedAt): float
    {
        $base = (float) $assignment->late_penalty_percent;

        if ($base <= 0 || $submittedAt === null) {
            return 0.0;
        }

        $due = $assignment->activity?->due_at;

        if ($due === null || $submittedAt->lessThanOrEqualTo($due)) {
            return 0.0;
        }

        $step = $assignment->late_penalty_days_step;

        if ($step !== null && $step > 0) {
            $steps = max(1, (int) ceil($due->diffInHours($submittedAt) / ($step * 24)));
            $penalty = $base * $steps;
        } else {
            $penalty = $base;
        }

        $cap = $assignment->late_penalty_max_percent;

        if ($cap !== null) {
            $penalty = min($penalty, (float) $cap);
        }

        return round(min($penalty, 100), 2);
    }

    /** Terapkan penalti: nilai asli → nilai akhir (§9). */
    public function applyPenalty(float $grade, float $percent): float
    {
        if ($percent <= 0) {
            return round($grade, 2);
        }

        return round(max(0, $grade * (1 - $percent / 100)), 2);
    }

    /** Ringkasan penilaian untuk header halaman guru (§11). */
    public function summary(LmsActivity $activity): array
    {
        $assignment = $activity->assignment;

        if ($assignment === null) {
            return [];
        }

        return [
            'maximum_grade' => (float) $assignment->maximum_grade,
            'passing_grade' => $assignment->passing_grade === null ? null : (float) $assignment->passing_grade,
            'allow_late_submission' => (bool) $assignment->allow_late_submission,
            'late_penalty_percent' => (float) $assignment->late_penalty_percent,
            'maximum_attempts' => $assignment->maxAttempts(),
        ];
    }
}
