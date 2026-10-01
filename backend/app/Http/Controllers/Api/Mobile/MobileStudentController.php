<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\AssignmentSubmissionResource;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\ClassStudent;
use App\Services\Mobile\ContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Aksi siswa atas tugas ERP (`assignments`).
 *
 * API tugas yang ada hanya mengenal "petugas menginput pengumpulan atas nama
 * siswa" (`POST assignments/{id}/submissions` butuh `academic.create`, menerima
 * `student_id` dan `submitted_at` dari klien). Siswa tak punya jalur sendiri,
 * dan jalur itu tidak boleh dibuka begitu saja: klien bisa memalsukan waktu
 * kumpul. Di sini identitas SISWA dan WAKTU kumpul ditentukan server.
 */
class MobileStudentController extends BaseApiController
{
    public function __construct(private readonly ContextService $contexts) {}

    public function submitAssignment(Request $request, Assignment $assignment): JsonResponse
    {
        $studentId = $this->contexts->studentId($request->user());
        abort_if($studentId === null, 403, 'Akun ini tidak terhubung ke data siswa.');

        $data = $request->validate(['content' => ['required', 'string', 'min:1', 'max:20000']]);

        // Kelas tugas harus kelas aktif siswa ini; selain itu 404 (id tugas tidak dikonfirmasi).
        $member = ClassStudent::query()
            ->where('school_class_id', $assignment->class_id)
            ->where('student_id', $studentId)
            ->where('status', 'aktif')
            ->exists();
        abort_unless($member, 404, 'Tugas tidak ditemukan.');

        if (! $assignment->isPublished()) {
            throw ValidationException::withMessages(['assignment' => 'Tugas ini belum dibuka atau sudah ditutup.']);
        }

        $now = now();
        $late = $assignment->isLateAt($now);

        if ($late && ! $assignment->allow_late_submission) {
            throw ValidationException::withMessages(['assignment' => 'Batas pengumpulan sudah lewat dan tugas ini tidak menerima keterlambatan.']);
        }

        $existing = AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('student_id', $studentId)
            ->first();

        // Pengumpulan yang sudah dinilai tidak boleh ditimpa (nilai & umpan balik hilang);
        // yang "dikembalikan" guru boleh dikirim ulang.
        if ($existing?->status === AssignmentSubmission::STATUS_GRADED) {
            throw ValidationException::withMessages(['assignment' => 'Tugas ini sudah dinilai dan tidak dapat dikirim ulang.']);
        }

        $attributes = [
            'submitted_at' => $now,
            'content' => $data['content'],
            'status' => $late ? AssignmentSubmission::STATUS_LATE : AssignmentSubmission::STATUS_SUBMITTED,
        ];

        $submission = $existing
            ? tap($existing)->update($attributes)
            : AssignmentSubmission::create($attributes + ['assignment_id' => $assignment->id, 'student_id' => $studentId]);

        return $this->successResponse(
            new AssignmentSubmissionResource($submission->refresh()),
            $existing ? 'Pengumpulan diperbarui' : 'Tugas berhasil dikumpulkan',
            $existing ? 200 : 201,
        );
    }
}
