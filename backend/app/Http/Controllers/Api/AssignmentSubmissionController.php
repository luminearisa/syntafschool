<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Assignment\GradeSubmissionRequest;
use App\Http\Requests\Assignment\SubmissionRequest;
use App\Http\Resources\AssignmentResource;
use App\Http\Resources\AssignmentSubmissionResource;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\ClassStudent;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Pengumpulan & penilaian tugas.
 *
 * Aturan inti:
 *  - hanya tugas berstatus `published` yang boleh dikumpulkan;
 *  - pengumpulan setelah `due_date` ditolak bila `allow_late_submission` mati,
 *    dan disimpan berstatus `late` bila diizinkan;
 *  - satu siswa hanya punya satu pengumpulan per tugas (pengumpulan ulang
 *    memperbarui baris yang ada);
 *  - nilai tidak boleh melebihi `max_score` tugas.
 */
class AssignmentSubmissionController extends BaseApiController
{
    /** @var list<string> */
    protected array $with = ['student', 'grader'];

    public function store(SubmissionRequest $request, Assignment $assignment): JsonResponse
    {
        $data = $request->validated();

        $this->assertPublished($assignment);

        $student = Student::findOrFail($data['student_id']);
        $this->assertStudentInClass($assignment, $student);

        $submittedAt = isset($data['submitted_at'])
            ? \Illuminate\Support\Carbon::parse($data['submitted_at'])
            : now();

        $late = $assignment->isLateAt($submittedAt);

        if ($late && ! $assignment->allow_late_submission) {
            throw ValidationException::withMessages([
                'submitted_at' => 'Pengumpulan melewati batas waktu dan tugas ini tidak mengizinkan keterlambatan.',
            ]);
        }

        $existing = AssignmentSubmission::where('assignment_id', $assignment->id)
            ->where('student_id', $student->id)
            ->first();

        $attributes = [
            'submitted_at' => $submittedAt,
            'content' => $data['content'] ?? null,
            'attachment_path' => $data['attachment_path'] ?? null,
            'status' => $late ? AssignmentSubmission::STATUS_LATE : AssignmentSubmission::STATUS_SUBMITTED,
        ];

        if ($existing) {
            $existing->update($attributes);
            $submission = $existing;
        } else {
            $submission = AssignmentSubmission::create($attributes + [
                'assignment_id' => $assignment->id,
                'student_id' => $student->id,
            ]);
        }

        $resource = new AssignmentSubmissionResource($submission->load($this->with));

        return $existing
            ? $this->successResponse($resource, 'Pengumpulan berhasil diperbarui')
            : $this->createdResponse($resource, 'Pengumpulan berhasil disimpan');
    }

    public function update(SubmissionRequest $request, Assignment $assignment, AssignmentSubmission $submission): JsonResponse
    {
        $this->assertBelongsToAssignment($assignment, $submission);

        $data = $request->validated();

        $attributes = [
            'content' => $data['content'] ?? $submission->content,
            'attachment_path' => $data['attachment_path'] ?? $submission->attachment_path,
        ];

        // Bila waktu pengumpulan diubah, status keterlambatan dihitung ulang.
        if (! empty($data['submitted_at'])) {
            $submittedAt = \Illuminate\Support\Carbon::parse($data['submitted_at']);
            $late = $assignment->isLateAt($submittedAt);

            if ($late && ! $assignment->allow_late_submission) {
                throw ValidationException::withMessages([
                    'submitted_at' => 'Pengumpulan melewati batas waktu dan tugas ini tidak mengizinkan keterlambatan.',
                ]);
            }

            $attributes['submitted_at'] = $submittedAt;
            $attributes['status'] = $late
                ? AssignmentSubmission::STATUS_LATE
                : AssignmentSubmission::STATUS_SUBMITTED;
        }

        $submission->update($attributes);

        return $this->successResponse(
            new AssignmentSubmissionResource($submission->refresh()->load($this->with)),
            'Pengumpulan berhasil diperbarui',
        );
    }

    public function grade(GradeSubmissionRequest $request, Assignment $assignment, AssignmentSubmission $submission): JsonResponse
    {
        $this->assertBelongsToAssignment($assignment, $submission);

        // Hanya pengumpulan yang benar-benar sudah dikumpulkan yang bisa dinilai.
        if (! $submission->hasSubmitted()) {
            throw ValidationException::withMessages([
                'submission' => 'Siswa belum mengumpulkan tugas ini sehingga belum bisa dinilai.',
            ]);
        }

        $data = $request->validated();

        if ((float) $data['score'] > (float) $assignment->max_score) {
            throw ValidationException::withMessages([
                'score' => 'Nilai tidak boleh melebihi nilai maksimum tugas ('.$assignment->max_score.').',
            ]);
        }

        $submission->update([
            'score' => $data['score'],
            'feedback' => $data['feedback'] ?? null,
            'graded_by' => $request->user()?->id,
            'graded_at' => now(),
            'status' => AssignmentSubmission::STATUS_GRADED,
        ]);

        return $this->successResponse(
            new AssignmentSubmissionResource($submission->refresh()->load($this->with)),
            'Nilai pengumpulan berhasil disimpan',
        );
    }

    public function destroy(Assignment $assignment, AssignmentSubmission $submission): JsonResponse
    {
        $this->assertBelongsToAssignment($assignment, $submission);

        $submission->delete();

        return $this->successResponse(null, 'Pengumpulan berhasil dihapus');
    }

    /**
     * Daftar tugas milik satu siswa beserta status pengumpulannya.
     */
    public function studentAssignments(Request $request, Student $student): JsonResponse
    {
        $semesterId = $request->query('semester_id');

        $classIds = ClassStudent::where('student_id', $student->id)->pluck('school_class_id');

        $assignments = Assignment::query()
            ->with(['schoolClass', 'subject', 'teacher', 'semester'])
            ->withCount([
                'submissions',
                'submissions as graded_count' => fn ($q) => $q->where('status', AssignmentSubmission::STATUS_GRADED),
            ])
            ->whereIn('class_id', $classIds)
            ->where('status', '!=', Assignment::STATUS_DRAFT)
            ->when($semesterId, fn ($q) => $q->where('semester_id', $semesterId))
            ->orderByDesc('due_date')
            ->get();

        $submissions = AssignmentSubmission::query()
            ->with($this->with)
            ->where('student_id', $student->id)
            ->whereIn('assignment_id', $assignments->pluck('id'))
            ->get()
            ->keyBy('assignment_id');

        $rows = $assignments->map(function (Assignment $assignment) use ($submissions) {
            $submission = $submissions->get($assignment->id);

            return [
                'assignment' => (new AssignmentResource($assignment))->resolve(),
                'submission' => $submission
                    ? (new AssignmentSubmissionResource($submission))->resolve()
                    : null,
                'status' => $submission?->status ?? AssignmentSubmission::STATUS_PENDING,
            ];
        })->values();

        return $this->successResponse($rows, 'Daftar tugas siswa berhasil diambil');
    }

    protected function assertPublished(Assignment $assignment): void
    {
        if (! $assignment->isPublished()) {
            throw ValidationException::withMessages([
                'assignment' => 'Tugas ini belum dipublikasikan atau sudah ditutup, pengumpulan tidak dibuka.',
            ]);
        }
    }

    protected function assertStudentInClass(Assignment $assignment, Student $student): void
    {
        $isMember = ClassStudent::where('school_class_id', $assignment->class_id)
            ->where('student_id', $student->id)
            ->exists();

        if (! $isMember) {
            throw ValidationException::withMessages([
                'student_id' => 'Siswa ini bukan anggota kelas dari tugas tersebut.',
            ]);
        }
    }

    protected function assertBelongsToAssignment(Assignment $assignment, AssignmentSubmission $submission): void
    {
        if ((int) $submission->assignment_id !== (int) $assignment->id) {
            abort(404, 'Pengumpulan tidak ditemukan pada tugas ini.');
        }
    }
}
