<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Contracts\ActivityConfiguratorInterface;
use App\Modules\LMS\Domain\Enums\AssignmentSubmissionStatus;
use App\Modules\LMS\Domain\Enums\GradingType;
use App\Modules\LMS\Domain\Enums\SubmissionType;
use App\Modules\LMS\Infrastructure\Support\HtmlSanitizer;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsAssignment;
use App\Modules\LMS\Models\LmsAssignmentSubmission;
use App\Modules\LMS\Models\LmsEnrollment;
use Illuminate\Support\Collection;

/**
 * Tugas (§3–§4, §10–§11).
 *
 * Konfigurasi tugas disimpan terpisah dari aktivitasnya supaya mesin aktivitas
 * tetap netral; yang lain (visibilitas, prasyarat, penyelesaian, nilai) dipakai
 * bersama.
 */
class AssignmentService implements ActivityConfiguratorInterface
{
    use LogsLmsAudit;

    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    public function createForActivity(LmsActivity $activity, array $data): void
    {
        LmsAssignment::create([
            'activity_id' => $activity->id,
            'submission_type' => $data['submission_type'] ?? SubmissionType::ONLINE_TEXT_AND_FILE->value,
            'grading_type' => $data['grading_type'] ?? GradingType::POINTS->value,
            'maximum_grade' => $data['maximum_grade'] ?? 100,
            'passing_grade' => $data['passing_grade'] ?? null,
            'maximum_attempts' => $data['maximum_attempts'] ?? 1,
            'allow_late_submission' => $data['allow_late_submission'] ?? true,
            'late_penalty_percent' => $data['late_penalty_percent'] ?? 0,
            'late_penalty_days_step' => $data['late_penalty_days_step'] ?? null,
            'late_penalty_max_percent' => $data['late_penalty_max_percent'] ?? null,
            'group_submission' => $data['group_submission'] ?? false,
            'require_file' => $data['require_file'] ?? ($data['submission_type'] ?? null) === SubmissionType::FILE->value,
            'require_text' => $data['require_text'] ?? false,
            'allowed_file_types' => $data['allowed_file_types'] ?? null,
            'max_file_size_kb' => $data['max_file_size_kb'] ?? null,
            'settings' => $data['assignment_settings'] ?? null,
        ]);
    }

    public function updateForActivity(LmsActivity $activity, array $data): void
    {
        $assignment = $this->config($activity);

        if ($assignment === null) {
            return;
        }

        $assignment->fill(collect($data)->only([
            'submission_type', 'grading_type', 'maximum_grade', 'passing_grade', 'maximum_attempts',
            'allow_late_submission', 'late_penalty_percent', 'late_penalty_days_step',
            'late_penalty_max_percent', 'group_submission', 'require_file', 'require_text',
            'allowed_file_types', 'max_file_size_kb',
        ])->all());

        if (array_key_exists('assignment_settings', $data)) {
            $assignment->settings = $data['assignment_settings'];
        }

        $assignment->save();
    }

    public function duplicateForActivity(LmsActivity $source, LmsActivity $copy): void
    {
        $assignment = $this->config($source);

        if ($assignment === null) {
            return;
        }

        $this->createForActivity($copy, array_merge($assignment->toArray(), [
            'assignment_settings' => $assignment->settings,
        ]));
    }

    public function config(LmsActivity $activity): ?LmsAssignment
    {
        return $activity->assignment ?? $activity->load('assignment')->assignment;
    }

    /** Pastikan aktivitas bertipe tugas punya baris konfigurasi. */
    public function ensureConfig(LmsActivity $activity): LmsAssignment
    {
        $config = $this->config($activity);

        if ($config === null) {
            $this->createForActivity($activity, []);
            $config = $this->config($activity->fresh('assignment'));
        }

        return $config;
    }

    /**
     * Tampilan tugas untuk siswa (§10) — termasuk aturan lamaran terlambat.
     *
     * @return array<string, mixed>
     */
    public function studentView(LmsActivity $activity, LmsEnrollment $enrollment, int $userId): array
    {
        $config = $this->ensureConfig($activity);

        $submissions = LmsAssignmentSubmission::query()
            ->where('assignment_id', $config->id)
            ->where('user_id', $userId)
            ->with('files')
            ->orderByDesc('attempt')
            ->get();

        $latest = $submissions->first();
        $attemptsUsed = $submissions->whereNotNull('submitted_at')->count();
        $remaining = max(0, $config->maxAttempts() - $attemptsUsed);
        $open = $activity->submissionWindowOpen();

        return [
            'assignment' => $this->configPayload($config),
            'status' => $latest?->status->value ?? 'NOT_SUBMITTED',
            'status_label' => $latest?->status->label() ?? 'Belum dikumpulkan',
            'attempts_used' => $attemptsUsed,
            'attempts_remaining' => $remaining,
            'can_submit' => $open && $remaining > 0 && ($latest === null || $latest->isEditable()),
            'submission' => $latest === null ? null : $this->submissionPayload($latest),
            'submissions' => $submissions->map(fn (LmsAssignmentSubmission $submission) => $this->submissionPayload($submission))->all(),
            'allowed_file_types' => $config->allowedFileTypes(),
            'late_policy' => $this->latePolicyPayload($config, $activity),
            'grades' => [
                'maximum' => (float) $config->maximum_grade,
                'passing' => $config->passing_grade === null ? null : (float) $config->passing_grade,
                'type' => $config->grading_type?->value,
            ],
        ];
    }

    /**
     * Tabel submission guru dengan filter (§11).
     *
     * @param  array<string, mixed>  $filters
     */
    public function teacherOverview(LmsActivity $activity, array $filters = [], int $perPage = 25)
    {
        $config = $this->ensureConfig($activity);

        $query = LmsAssignmentSubmission::query()
            ->where('assignment_id', $config->id)
            ->with(['files', 'user'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(($filters['late'] ?? null) === 'true', fn ($q) => $q->where('late', true))
            ->when(($filters['late'] ?? null) === 'false', fn ($q) => $q->where('late', false));

        if (($filters['graded'] ?? null) === 'true') {
            $query->whereNotNull('graded_at');
        }

        if (($filters['graded'] ?? null) === 'false') {
            $query->whereNull('graded_at');
        }

        return $query->orderByDesc('submitted_at')->orderByDesc('id')->paginate($perPage);
    }

    /**
     * Statistik tugas (§11) — ringkas dan cepat dibaca guru.
     *
     * @return array<string, mixed>
     */
    public function statistics(LmsActivity $activity): array
    {
        $config = $this->ensureConfig($activity);

        $submissions = LmsAssignmentSubmission::query()->where('assignment_id', $config->id);

        $participants = LmsEnrollment::query()
            ->where('course_id', $activity->course_id)
            ->whereIn('status', ['ACTIVE', 'COMPLETED'])
            ->where('role', 'STUDENT')
            ->count();

        $submitted = (clone $submissions)->whereNotNull('submitted_at')->count();
        $graded = (clone $submissions)->whereNotNull('graded_at')->count();
        $late = (clone $submissions)->whereNotNull('submitted_at')->where('late', true)->count();
        $average = (clone $submissions)->whereNotNull('grade')->avg('grade');
        $highest = (clone $submissions)->whereNotNull('grade')->max('grade');
        $lowest = (clone $submissions)->whereNotNull('grade')->min('grade');
        $passed = (clone $submissions)->where('passed', true)->count();

        return [
            'participants' => $participants,
            'submitted' => $submitted,
            'not_submitted' => max(0, $participants - $submitted),
            'graded' => $graded,
            'ungraded' => max(0, $submitted - $graded),
            'late' => $late,
            'average' => $average === null ? null : round((float) $average, 2),
            'highest' => $highest === null ? null : (float) $highest,
            'lowest' => $lowest === null ? null : (float) $lowest,
            'passed' => $passed,
            'pass_rate' => $submitted > 0 ? round($passed / $submitted * 100, 2) : null,
            'submission_rate' => $participants > 0 ? round($submitted / $participants * 100, 2) : null,
            'maximum_grade' => (float) $config->maximum_grade,
        ];
    }

    /** @return array<string, mixed> */
    public function configPayload(LmsAssignment $config): array
    {
        return [
            'id' => (int) $config->id,
            'submission_type' => $config->submission_type?->value,
            'submission_type_label' => $config->submission_type?->label(),
            'grading_type' => $config->grading_type?->value,
            'maximum_grade' => (float) $config->maximum_grade,
            'passing_grade' => $config->passing_grade === null ? null : (float) $config->passing_grade,
            'maximum_attempts' => $config->maxAttempts(),
            'allow_late_submission' => (bool) $config->allow_late_submission,
            'late_penalty_percent' => (float) $config->late_penalty_percent,
            'late_penalty_days_step' => $config->late_penalty_days_step === null ? null : (int) $config->late_penalty_days_step,
            'group_submission' => (bool) $config->group_submission,
            'require_file' => (bool) $config->require_file,
            'require_text' => (bool) $config->require_text,
            'allowed_file_types' => $config->allowedFileTypes(),
            'max_file_size_kb' => $config->max_file_size_kb === null ? null : (int) $config->max_file_size_kb,
            'settings' => $config->settings,
        ];
    }

    /** @return array<string, mixed> */
    public function submissionPayload(LmsAssignmentSubmission $submission): array
    {
        return [
            'id' => (int) $submission->id,
            'attempt' => (int) $submission->attempt,
            'status' => $submission->status?->value,
            'status_label' => $submission->status?->label(),
            'text_content' => $submission->text_content,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'graded_at' => $submission->graded_at?->toIso8601String(),
            'grade' => $submission->grade === null ? null : (float) $submission->grade,
            'original_grade' => $submission->original_grade === null ? null : (float) $submission->original_grade,
            'late_penalty_percent' => $submission->late_penalty_percent === null ? null : (float) $submission->late_penalty_percent,
            'late' => (bool) $submission->late,
            'passed' => $submission->passed,
            'feedback' => $submission->feedback,
            'grader_id' => $submission->grader_id === null ? null : (int) $submission->grader_id,
            'return_reason' => $submission->return_reason,
            'files' => $submission->files->map(fn ($file) => [
                'id' => (int) $file->id,
                'name' => (string) $file->file_name,
                'size' => (int) $file->file_size,
                'size_label' => $file->humanSize(),
                'type' => $file->file_type,
                'purpose' => $file->purpose,
                'url' => '/api/v1/lms/submission-files/'.$file->id,
            ])->all(),
        ];
    }

    /**
     * Kebijakan keterlambatan yang berlaku sekarang (§9).
     *
     * @return array<string, mixed>
     */
    public function latePolicyPayload(LmsAssignment $config, LmsActivity $activity): array
    {
        return [
            'due_at' => $activity->due_at?->toIso8601String(),
            'cutoff_at' => ($activity->close_at ?? $activity->due_at)?->toIso8601String(),
            'allow_late' => (bool) $config->allow_late_submission,
            'penalty_percent' => (float) $config->late_penalty_percent,
            'is_past_due' => $activity->isPastDue(),
        ];
    }

    /** @return Collection<int, LmsAssignmentSubmission> */
    public function submissions(LmsActivity $activity): Collection
    {
        $config = $this->ensureConfig($activity);

        return LmsAssignmentSubmission::query()->where('assignment_id', $config->id)->get();
    }

    /** Jumlah pengumpulan yang menunggu penilaian (§50). */
    public function pendingGradingCount(LmsActivity $activity): int
    {
        $config = $this->ensureConfig($activity);

        return LmsAssignmentSubmission::query()
            ->where('assignment_id', $config->id)
            ->whereNotNull('submitted_at')
            ->whereNull('graded_at')
            ->whereIn('status', [
                AssignmentSubmissionStatus::SUBMITTED->value,
                AssignmentSubmissionStatus::RESUBMITTED->value,
            ])
            ->count();
    }
}
