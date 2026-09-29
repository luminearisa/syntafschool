<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\ActivityAvailabilityService;
use App\Modules\LMS\Application\Services\AssignmentGradingService;
use App\Modules\LMS\Application\Services\AssignmentService;
use App\Modules\LMS\Application\Services\AssignmentSubmissionService;
use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Domain\Enums\AssignmentSubmissionStatus;
use App\Modules\LMS\Http\Controllers\Concerns\ResolvesActivities;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsAssignmentSubmission;
use App\Modules\LMS\Models\LmsAssignmentSubmissionFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pengumpulan tugas: simpan draf, kirim, unggah berkas, unduh ber-otorisasi,
 * dan penilaian (§5–§7, §9, §53–§54).
 */
class LmsSubmissionController extends LmsController
{
    use ResolvesActivities;

    public function __construct(
        private readonly AssignmentSubmissionService $submissions,
        private readonly AssignmentGradingService $grading,
        private readonly AssignmentService $assignments,
        private readonly CourseAccessService $access,
        private readonly ActivityAvailabilityService $availability,
    ) {}

    /** Simpan draf sebelum dikirim (§5). */
    public function saveDraft(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        [$actor, $enrollment] = $this->requireEnrollment($request, $model);

        $data = $this->validatePayload($request, [
            'text_content' => ['nullable', 'string'],
        ]);

        $draft = $this->submissions->saveDraft($model, $enrollment, $actor->id, $data, $actor->studentId);

        return $this->successResponse(
            $this->assignments->submissionPayload($draft),
            'Draf tersimpan',
        );
    }

    /** Kirim tugas (§5, §9). */
    public function submit(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        [$actor, $enrollment] = $this->requireEnrollment($request, $model);

        $state = $this->availability->studentState($model, $enrollment);

        $this->authorizeLms(! $state['locked'], $state['reason_label'] ?? 'Aktivitas terkunci.');

        $data = $this->validatePayload($request, [
            'text_content' => ['nullable', 'string'],
        ]);

        $submission = $this->submissions->submit($model, $enrollment, $actor->id, $data, $actor, $actor->studentId);

        return $this->successResponse(
            $this->assignments->submissionPayload($submission),
            'Tugas berhasil dikirim',
        );
    }

    /** Riwayat percobaan pengumpulan siswa (termasuk yang lama, §6). */
    public function index(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $actor = $this->actor($request);
        $config = $this->assignments->ensureConfig($model);

        $isStaff = $this->access->canGrade($model->course, $actor);

        if ($isStaff) {
            $filters = $request->only(['status', 'late', 'graded']);

            return $this->successResponse(
                $this->assignments->teacherOverview($model, $filters, $this->perPage($request, 25))
                    ->through(fn (LmsAssignmentSubmission $submission) => $this->assignments->submissionPayload($submission) + [
                        'student' => [
                            'user_id' => (int) $submission->user_id,
                            'name' => $submission->user?->name,
                        ],
                    ]),
                'Daftar pengumpulan berhasil diambil',
            );
        }

        // Siswa hanya melihat pengumpulannya sendiri (§54).
        return $this->successResponse(
            $this->submissions->attempts($config, $actor->id)
                ->map(fn (LmsAssignmentSubmission $submission) => $this->assignments->submissionPayload($submission))
                ->all(),
            'Riwayat pengumpulan berhasil diambil',
        );
    }

    /** Detail satu submission; siswa hanya miliknya sendiri (§54). */
    public function show(Request $request, LmsAssignmentSubmission $submission): JsonResponse
    {
        $actor = $this->actor($request);
        $activity = $submission->assignment?->activity;
        $course = $activity?->course;

        $this->authorizeLms($submission->isOwnedBy($actor->id) || $this->access->canGrade($course, $actor), 'Anda tidak berhak melihat pengumpulan ini.');

        return $this->successResponse(
            $this->assignments->submissionPayload($submission->load('files')),
            'Detail pengumpulan berhasil diambil',
        );
    }

    /** Unggah berkas submission (§6). */
    public function upload(Request $request, LmsAssignmentSubmission $submission): JsonResponse
    {
        $actor = $this->actor($request);

        $this->authorizeLms($submission->isOwnedBy($actor->id), 'Anda hanya dapat mengunggah pada pengumpulan sendiri.');

        $request->validate([
            'file' => ['required', 'file', 'max:'.(int) config('lms.uploads.max_kb', 20480)],
        ]);

        $file = $this->submissions->storeFile($submission, $request->file('file'), $actor);

        return $this->createdResponse([
            'id' => (int) $file->id,
            'name' => (string) $file->file_name,
            'type' => $file->file_type,
            'size' => (int) $file->file_size,
            'size_label' => $file->humanSize(),
        ], 'Berkas berhasil diunggah');
    }

    public function deleteFile(Request $request, LmsAssignmentSubmissionFile $file): JsonResponse
    {
        $actor = $this->actor($request);

        $this->authorizeLms($file->submission?->isOwnedBy($actor->id) ?? false, 'Anda tidak berhak menghapus berkas ini.');

        $this->submissions->deleteFile($file, $actor);

        return $this->successResponse(null, 'Berkas berhasil dihapus');
    }

    /** Unduhan ber-otorisasi: siswa pemilik, guru penilai, atau wali (§54). */
    public function download(Request $request, LmsAssignmentSubmissionFile $file): StreamedResponse
    {
        $actor = $this->actor($request);
        $submission = $file->submission;
        $activity = $submission?->assignment?->activity;
        $course = $activity?->course;

        $allowed = $submission !== null && (
            $submission->isOwnedBy($actor->id)
            || $this->access->canGrade($course, $actor)
            || $actor->can('lms.manage')
        );

        abort_unless($allowed, 403, 'Anda tidak berhak mengunduh berkas ini.');

        $disk = (string) config('lms.uploads.disk', 'local');
        abort_unless(Storage::disk($disk)->exists($file->file_path), 404, 'Berkas tidak ditemukan pada penyimpanan.');

        return Storage::disk($disk)->download($file->file_path, $file->file_name);
    }

    /** Beri nilai + umpan balik (§7). */
    public function grade(Request $request, LmsAssignmentSubmission $submission): JsonResponse
    {
        $activity = $submission->assignment?->activity;
        $this->authorizeGrade($request, $activity);

        $data = $this->validatePayload($request, [
            'grade' => ['required', 'numeric', 'min:0'],
            'feedback' => ['nullable', 'string'],
            'feedback_file' => ['nullable', 'file', 'max:'.(int) config('lms.uploads.max_kb', 20480)],
        ]);

        $data['feedback_file'] = $request->file('feedback_file');

        return $this->successResponse(
            $this->assignments->submissionPayload($this->grading->grade($submission, $data, $this->actor($request))),
            'Nilai dan umpan balik berhasil disimpan',
        );
    }

    /** Kembalikan submission agar siswa memperbaiki (§5). */
    public function returnToStudent(Request $request, LmsAssignmentSubmission $submission): JsonResponse
    {
        $activity = $submission->assignment?->activity;
        $actor = $this->authorizeGrade($request, $activity);

        $data = $this->validatePayload($request, ['reason' => ['nullable', 'string', 'max:500']]);

        return $this->successResponse(
            $this->assignments->submissionPayload($this->grading->returnToStudent($submission, $data['reason'] ?? null, $actor)),
            'Pengumpulan dikembalikan ke siswa',
        );
    }

    /** Tolak submission (mis. melewati cutoff) (§9). */
    public function reject(Request $request, LmsAssignmentSubmission $submission): JsonResponse
    {
        $activity = $submission->assignment?->activity;
        $actor = $this->authorizeGrade($request, $activity);

        $data = $this->validatePayload($request, ['reason' => ['nullable', 'string', 'max:500']]);

        return $this->successResponse(
            $this->assignments->submissionPayload($this->grading->reject($submission, $data['reason'] ?? null, $actor)),
            'Pengumpulan ditolak',
        );
    }

    /** Status submission untuk siswa (mis. "belum dikirim"). */
    public function status(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        [$actor, $enrollment] = $this->requireEnrollment($request, $model);

        $config = $this->assignments->ensureConfig($model);
        $latest = $this->submissions->latest($config, $actor->id);

        return $this->successResponse([
            'status' => $latest?->status?->value ?? AssignmentSubmissionStatus::DRAFT->value,
            'status_label' => $latest?->status?->label() ?? 'Belum dikumpulkan',
            'attempt' => $latest?->attempt ?? 0,
            'submitted_at' => $latest?->submitted_at?->toIso8601String(),
            'grade' => $latest?->grade === null ? null : (float) $latest->grade,
            'late' => (bool) ($latest->late ?? false),
        ], 'Status pengumpulan berhasil diambil');
    }
}
