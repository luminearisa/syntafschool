<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\ActorRole;
use App\Modules\LMS\Domain\Enums\AssignmentSubmissionStatus;
use App\Modules\LMS\Domain\Enums\SubmissionType;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsAssignment;
use App\Modules\LMS\Models\LmsAssignmentSubmission;
use App\Modules\LMS\Models\LmsAssignmentSubmissionFile;
use App\Modules\LMS\Models\LmsEnrollment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pengumpulan tugas (§5–§7, §9).
 *
 * Aturan yang dijaga ketat di sini:
 *  - draf boleh diubah berkali-kali, submission final tidak dapat ditimpa;
 *  - setelah cutoff tidak ada pengumpulan baru (walau `allow_late_submission`);
 *  - attempt lama tidak pernah dihapus — resubmission membuat attempt baru;
 *  - berkas memakai disk Laravel yang sama dengan materi (§6).
 */
class AssignmentSubmissionService
{
    use LogsLmsAudit;

    /** Ekstensi yang dikenali per kategori tipe berkas yang diizinkan (§4). */
    private const FILE_EXTENSIONS = [
        'PDF' => ['pdf'],
        'DOC' => ['doc'],
        'DOCX' => ['docx'],
        'PPT' => ['ppt'],
        'PPTX' => ['pptx'],
        'XLS' => ['xls'],
        'XLSX' => ['xlsx'],
        'ZIP' => ['zip', 'rar', '7z'],
        'IMAGE' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
    ];

    public function __construct(
        private readonly AssignmentService $assignments,
        private readonly ActivityCompletionService $completion,
    ) {}

    /** Submission terakhir milik siswa (attempt tertinggi). */
    public function latest(LmsAssignment $assignment, int $userId): ?LmsAssignmentSubmission
    {
        return LmsAssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('user_id', $userId)
            ->with('files')
            ->orderByDesc('attempt')
            ->first();
    }

    /** @return Collection<int, LmsAssignmentSubmission> */
    public function attempts(LmsAssignment $assignment, int $userId): Collection
    {
        return LmsAssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('user_id', $userId)
            ->with('files')
            ->orderBy('attempt')
            ->get();
    }

    /**
     * Simpan draf. Idempoten: dipanggil berulang hanya memperbarui draf yang sama.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(
        LmsActivity $activity,
        LmsEnrollment $enrollment,
        int $userId,
        array $data = [],
        ?int $studentId = null,
    ): LmsAssignmentSubmission {
        $assignment = $this->assignments->ensureConfig($activity);
        $this->assertCanWorkOn($activity, $assignment, $userId);

        $draft = LmsAssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('user_id', $userId)
            ->where('status', AssignmentSubmissionStatus::DRAFT->value)
            ->orderByDesc('attempt')
            ->first();

        if ($draft === null) {
            $draft = new LmsAssignmentSubmission([
                'assignment_id' => $assignment->id,
                'user_id' => $userId,
                'student_id' => $studentId,
                'attempt' => $this->nextAttemptNumber($assignment, $userId),
                'status' => AssignmentSubmissionStatus::DRAFT->value,
                'metadata' => ['enrollment_id' => $enrollment->id],
            ]);
        }

        if (array_key_exists('text_content', $data)) {
            $draft->text_content = $data['text_content'];
        }

        $draft->save();

        $this->audit('LMS_SUBMISSION_DRAFT_SAVED', $activity, [
            'submission_id' => $draft->id,
            'user_id' => $userId,
        ]);

        return $draft->fresh('files');
    }

    /**
     * Kirim tugas (§5, §9).
     *
     * @param  array<string, mixed>  $data
     */
    public function submit(
        LmsActivity $activity,
        LmsEnrollment $enrollment,
        int $userId,
        array $data = [],
        ?LmsActor $actor = null,
        ?int $studentId = null,
    ): LmsAssignmentSubmission {
        $assignment = $this->assignments->ensureConfig($activity);
        $actor ??= new LmsActor(
            id: $userId,
            name: 'Siswa',
            email: null,
            avatarPath: null,
            primaryRole: ActorRole::STUDENT,
            roles: ['STUDENT'],
        );

        return DB::transaction(function () use ($activity, $assignment, $enrollment, $userId, $data, $studentId): LmsAssignmentSubmission {
            $draft = LmsAssignmentSubmission::query()
                ->where('assignment_id', $assignment->id)
                ->where('user_id', $userId)
                ->where('status', AssignmentSubmissionStatus::DRAFT->value)
                ->orderByDesc('attempt')
                ->with('files')
                ->lockForUpdate()
                ->first();

            if ($draft === null) {
                $draft = $this->saveDraft($activity, $enrollment, $userId, $data, $studentId);
                $draft = LmsAssignmentSubmission::query()->whereKey($draft->id)->with('files')->first();
            } elseif (array_key_exists('text_content', $data)) {
                $draft->text_content = $data['text_content'];
                $draft->save();
            }

            $now = Carbon::now();
            $late = $activity->due_at !== null && $now->greaterThan($activity->due_at);
            $cutoff = $activity->close_at ?? $activity->due_at;

            // Cutoff selalu keras: tidak ada pengumpulan setelahnya, sekalipun
            // guru mengizinkan keterlambatan (§9).
            if ($cutoff !== null && $now->greaterThan($cutoff)) {
                throw ValidationException::withMessages([
                    'submitted_at' => 'Batas akhir pengumpulan sudah lewat pada '.$cutoff->translatedFormat('d F Y H:i').'.',
                ]);
            }

            if ($late && ! $assignment->allow_late_submission) {
                throw ValidationException::withMessages([
                    'submitted_at' => 'Pengumpulan setelah tenggat tidak diizinkan untuk tugas ini.',
                ]);
            }

            $this->assertContentRequirements($assignment, $draft, $data);

            $draft->forceFill([
                'status' => $draft->attempt > 1
                    ? AssignmentSubmissionStatus::RESUBMITTED->value
                    : AssignmentSubmissionStatus::SUBMITTED->value,
                'submitted_at' => $now,
                'late' => $late,
            ])->save();

            // Penyelesaian course otomatis (§40).
            $this->completion->complete($activity, $enrollment, null, 'assignment_submitted', $userId, [
                'submission_id' => $draft->id,
                'late' => $late,
            ]);

            $this->audit('LMS_SUBMISSION_SUBMITTED', $activity, [
                'submission_id' => $draft->id,
                'user_id' => $userId,
                'attempt' => $draft->attempt,
                'late' => $late,
            ]);

            return $draft->fresh(['files']);
        });
    }

    /** Simpan berkas pada submission. Draf boleh ganti/ hapus; submission final tidak. */
    public function storeFile(LmsAssignmentSubmission $submission, UploadedFile $file, LmsActor $actor): LmsAssignmentSubmissionFile
    {
        $assignment = $submission->assignment;
        $activity = $assignment?->activity;
        $type = $this->classifyFile($file);

        $this->assertFileAllowed($submission, $file, $type);

        $disk = (string) config('lms.uploads.disk', 'local');
        $directory = trim((string) config('lms.uploads.directory', 'lms'), '/')
            .'/submissions/'.$assignment?->id;

        $path = $file->store($directory, $disk);

        $record = $submission->files()->create([
            'purpose' => 'SUBMISSION',
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_mime' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'file_type' => $type,
            'uploaded_by' => $actor->id,
        ]);

        $this->audit('LMS_SUBMISSION_FILE_UPLOADED', $activity, [
            'submission_id' => $submission->id,
            'file_id' => $record->id,
        ]);

        return $record;
    }

    public function deleteFile(LmsAssignmentSubmissionFile $file, ?LmsActor $actor = null): void
    {
        $submission = $file->submission;

        if ($submission !== null && ! $submission->isEditable()) {
            throw ValidationException::withMessages([
                'file' => 'Submission yang sudah dikirim tidak dapat diubah.',
            ]);
        }

        $file->delete();

        $this->audit('LMS_SUBMISSION_FILE_DELETED', $submission?->assignment?->activity, [
            'submission_id' => $submission?->id,
            'file_id' => $file->id,
        ]);
    }

    /** Berkas umpan balik dari guru (§7). */
    public function storeFeedbackFile(LmsAssignmentSubmission $submission, UploadedFile $file, LmsActor $actor): LmsAssignmentSubmissionFile
    {
        $disk = (string) config('lms.uploads.disk', 'local');
        $directory = trim((string) config('lms.uploads.directory', 'lms'), '/')
            .'/feedback/'.$submission->assignment_id;

        return $submission->files()->create([
            'purpose' => 'FEEDBACK',
            'file_path' => $file->store($directory, $disk),
            'file_name' => $file->getClientOriginalName(),
            'file_mime' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'file_type' => $this->classifyFile($file),
            'uploaded_by' => $actor->id,
        ]);
    }

    /** Path disk untuk unduhan ber-otorisasi (§54). */
    public function downloadPath(LmsAssignmentSubmissionFile $file): string
    {
        return $file->file_path;
    }

    /** Nomor attempt berikutnya (tidak pernah menimpa riwayat, §6). */
    public function nextAttemptNumber(LmsAssignment $assignment, int $userId): int
    {
        $max = (int) LmsAssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('user_id', $userId)
            ->max('attempt');

        return $max + 1;
    }

    // ------------------------------------------------------------------ validasi

    protected function assertCanWorkOn(LmsActivity $activity, LmsAssignment $assignment, int $userId): void
    {
        $cutoff = $activity->close_at ?? $activity->due_at;

        if ($cutoff !== null && $cutoff->isPast() && ! $assignment->allow_late_submission) {
            throw ValidationException::withMessages([
                'assignment' => 'Tugas sudah melewati batas waktu pengumpulan.',
            ]);
        }

        $submitted = LmsAssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('user_id', $userId)
            ->whereNotNull('submitted_at')
            ->count();

        if ($submitted >= $assignment->maxAttempts()) {
            $latest = $this->latest($assignment, $userId);

            if ($latest !== null && ! $latest->isEditable()) {
                throw ValidationException::withMessages([
                    'assignment' => 'Jumlah percobaan pengumpulan sudah habis ('.$assignment->maxAttempts().'x).',
                ]);
            }
        }
    }

    /** @param array<string, mixed> $data */
    protected function assertContentRequirements(LmsAssignment $assignment, LmsAssignmentSubmission $submission, array $data): void
    {
        $type = $assignment->submission_type ?? SubmissionType::ONLINE_TEXT_AND_FILE;

        $text = trim((string) ($data['text_content'] ?? $submission->text_content ?? ''));
        $hasFile = $submission->files()->where('purpose', 'SUBMISSION')->exists();

        if ($type->allowsText() && $assignment->require_text && $text === '') {
            throw ValidationException::withMessages(['text_content' => 'Jawaban teks wajib diisi.']);
        }

        if ($type->allowsFile() && $assignment->require_file && ! $hasFile) {
            throw ValidationException::withMessages(['files' => 'Berkas tugas wajib diunggah.']);
        }

        if (! $type->allowsText() && ! $type->allowsFile()) {
            throw ValidationException::withMessages(['submission_type' => 'Tugas ini tidak menerima pengumpulan.']);
        }
    }

    protected function assertFileAllowed(LmsAssignmentSubmission $submission, UploadedFile $file, string $type): void
    {
        if (! $submission->isEditable()) {
            throw ValidationException::withMessages([
                'file' => 'Submission yang sudah dikirim tidak dapat diubah.',
            ]);
        }

        $allowed = $submission->assignment?->allowedFileTypes() ?? LmsAssignment::DEFAULT_FILE_TYPES;

        if (! in_array($type, $allowed, true)) {
            throw ValidationException::withMessages([
                'file' => 'Tipe berkas '.$type.' tidak diizinkan. Tipe yang diterima: '.implode(', ', $allowed).'.',
            ]);
        }

        $maxKb = $submission->assignment?->max_file_size_kb;

        if ($maxKb !== null && $file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages([
                'file' => 'Ukuran berkas melebihi batas '.$maxKb.' KB.',
            ]);
        }
    }

    /** Klasifikasi berkas → kategori yang dipakai konfigurasi tugas (§4). */
    public function classifyFile(UploadedFile $file): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $mime = (string) $file->getClientMimeType();

        foreach (self::FILE_EXTENSIONS as $category => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $category;
            }
        }

        return str_starts_with($mime, 'image/') ? 'IMAGE' : 'OTHER';
    }
}
