<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Assignment\AssignmentRequest;
use App\Http\Resources\AssignmentResource;
use App\Http\Resources\AssignmentSubmissionResource;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\ClassStudent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tugas (assignment).
 *
 * Menjawab: "tugas apa saja yang diberikan untuk kelas ini, sudah berapa yang
 * dikumpulkan, dan berapa yang sudah dinilai?"
 */
class AssignmentController extends BaseApiController
{
    /** Kolom yang boleh difilter lewat query string. */
    protected array $filterable = [
        'class_id',
        'subject_id',
        'teacher_id',
        'semester_id',
        'status',
        'type',
    ];

    /** Relasi standar agar frontend tidak perlu request tambahan. */
    protected array $with = ['schoolClass', 'subject', 'teacher', 'semester'];

    public function index(Request $request): JsonResponse
    {
        $query = Assignment::query()
            ->with($this->with)
            ->withCount([
                'submissions',
                'submissions as graded_count' => fn ($q) => $q->where('status', AssignmentSubmission::STATUS_GRADED),
            ]);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '' || ! in_array($column, $this->filterable, true)) {
                continue;
            }
            $query->where($column, $value);
        }

        // Rentang tanggal berdasarkan batas pengumpulan.
        if ($from = $request->query('from')) {
            $query->whereDate('due_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('due_date', '<=', $to);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $query->orderByDesc('due_date')->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                AssignmentResource::collection($query->get()),
                'Daftar tugas berhasil diambil',
            );
        }

        return $this->successResponse(
            AssignmentResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar tugas berhasil diambil',
        );
    }

    public function store(AssignmentRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (! $this->access()->mayCreateAssignmentFor($request->user(), (int) $data['teacher_id'])) {
            abort(403, 'Anda hanya boleh membuat tugas atas nama sendiri.');
        }

        $data['created_by'] = $request->user()?->id;
        $data['status'] = $data['status'] ?? Assignment::STATUS_DRAFT;

        if ($data['status'] === Assignment::STATUS_PUBLISHED) {
            $data['published_at'] = now();
        }

        $assignment = Assignment::create($data);

        return $this->createdResponse(
            new AssignmentResource($this->loadForDisplay($assignment)),
            'Tugas berhasil dibuat',
        );
    }

    public function show(Assignment $assignment): JsonResponse
    {
        return $this->successResponse(
            new AssignmentResource($this->loadForDisplay($assignment)),
            'Detail tugas berhasil diambil',
        );
    }

    public function update(AssignmentRequest $request, Assignment $assignment): JsonResponse
    {
        $this->assertMayManage($request, $assignment);

        $data = $request->validated();

        if (! $this->access()->mayCreateAssignmentFor($request->user(), (int) $data['teacher_id'])) {
            abort(403, 'Anda tidak boleh mengalihkan tugas ke guru lain.');
        }


        if (($data['status'] ?? $assignment->status) === Assignment::STATUS_PUBLISHED) {
            $data['published_at'] = $assignment->published_at ?? now();
        } elseif (($data['status'] ?? null) === Assignment::STATUS_DRAFT) {
            $data['published_at'] = null;
        }

        $assignment->update($data);

        return $this->successResponse(
            new AssignmentResource($this->loadForDisplay($assignment)),
            'Tugas berhasil diperbarui',
        );
    }

    public function destroy(Assignment $assignment): JsonResponse
    {
        $assignment->delete();

        return $this->successResponse(null, 'Tugas berhasil dihapus');
    }

    /**
     * Publikasikan tugas sehingga siswa boleh mengumpulkan.
     */
    public function publish(Request $request, Assignment $assignment): JsonResponse
    {
        $this->assertMayManage($request, $assignment);

        $assignment->update([
            'status' => Assignment::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        return $this->successResponse(
            new AssignmentResource($this->loadForDisplay($assignment)),
            'Tugas berhasil dipublikasikan',
        );
    }

    /**
     * Tutup tugas. Pengumpulan yang sudah ada tetap tersimpan.
     */
    public function close(Request $request, Assignment $assignment): JsonResponse
    {
        $this->assertMayManage($request, $assignment);

        $assignment->update(['status' => Assignment::STATUS_CLOSED]);

        return $this->successResponse(
            new AssignmentResource($this->loadForDisplay($assignment)),
            'Tugas berhasil ditutup',
        );
    }

    /**
     * Daftar pengumpulan untuk sebuah tugas.
     *
     * Siswa yang belum mengumpulkan tetap ditampilkan dengan `status: pending`
     * agar guru tahu siapa yang masih tertunggak.
     */
    public function submissions(Assignment $assignment): JsonResponse
    {
        $submissions = AssignmentSubmission::query()
            ->with('student')
            ->where('assignment_id', $assignment->id)
            ->get()
            ->keyBy('student_id');

        $rows = ClassStudent::query()
            ->with('student')
            ->where('school_class_id', $assignment->class_id)
            ->get()
            ->map(function (ClassStudent $membership) use ($submissions) {
                $student = $membership->student;
                $submission = $student ? $submissions->get($student->id) : null;

                return [
                    'student_id' => $student?->id,
                    'nis' => $student?->nis,
                    'full_name' => $student?->full_name,
                    'submission_id' => $submission?->id,
                    'status' => $submission?->status ?? AssignmentSubmission::STATUS_PENDING,
                    'submitted_at' => $submission?->submitted_at?->toIso8601String(),
                    'score' => $submission?->score !== null ? (float) $submission->score : null,
                    'feedback' => $submission?->feedback,
                    'graded_at' => $submission?->graded_at?->toIso8601String(),
                    'has_submitted' => $submission?->submitted_at !== null,
                ];
            })
            ->sortBy('full_name')
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Daftar pengumpulan berhasil diambil',
            'data' => $rows,
            'meta' => [
                'total_students' => $rows->count(),
                'submitted' => $rows->where('has_submitted', true)->count(),
                'graded' => $rows->where('status', AssignmentSubmission::STATUS_GRADED)->count(),
                'pending' => $rows->where('status', AssignmentSubmission::STATUS_PENDING)->count(),
            ],
        ]);
    }

    /**
     * Rekap per kelas: jumlah tugas, terkumpul, belum, dan sudah dinilai.
     */
    public function recap(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
        ], [], [
            'class_id' => 'kelas',
            'semester_id' => 'semester',
        ]);

        $assignments = Assignment::query()
            ->where('class_id', $data['class_id'])
            ->where('semester_id', $data['semester_id'])
            ->withCount([
                'submissions',
                'submissions as graded_count' => fn ($q) => $q->where('status', AssignmentSubmission::STATUS_GRADED),
            ])
            ->orderBy('due_date')
            ->get();

        $totalStudents = ClassStudent::where('school_class_id', $data['class_id'])->count();

        // Tugas draft belum terlihat siswa, jadi tidak dihitung sebagai target.
        $visible = $assignments->where('status', '!=', Assignment::STATUS_DRAFT);

        $expected = $visible->count() * $totalStudents;
        $submitted = (int) $visible->sum('submissions_count');
        $graded = (int) $visible->sum('graded_count');

        return $this->successResponse([
            'class_id' => (int) $data['class_id'],
            'semester_id' => (int) $data['semester_id'],
            'total_assignments' => $assignments->count(),
            'published_assignments' => $visible->count(),
            'total_students' => $totalStudents,
            'expected_submissions' => $expected,
            'submitted' => $submitted,
            'pending' => max(0, $expected - $submitted),
            'graded' => $graded,
            'items' => $assignments->map(fn (Assignment $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'type' => $a->type,
                'status' => $a->status,
                'due_date' => $a->due_date?->toDateString(),
                'submitted' => (int) $a->submissions_count,
                'graded' => (int) $a->graded_count,
                'pending' => $a->status === Assignment::STATUS_DRAFT
                    ? $totalStudents
                    : max(0, $totalStudents - (int) $a->submissions_count),
            ])->values(),
        ], 'Rekap tugas berhasil diambil');
    }

    /**
     * Muat relasi + hitungan yang dipakai AssignmentResource.
     */
    protected function loadForDisplay(Assignment $assignment): Assignment
    {
        return $assignment->load($this->with)->loadCount([
            'submissions',
            'submissions as graded_count' => fn ($q) => $q->where('status', AssignmentSubmission::STATUS_GRADED),
        ]);
    }

    protected function access(): \App\Services\Academic\TeachingAccess
    {
        return app(\App\Services\Academic\TeachingAccess::class);
    }

    /** Guru hanya mengelola tugasnya sendiri (lihat TeachingAccess). */
    protected function assertMayManage(Request $request, Assignment $assignment): void
    {
        if (! $this->access()->mayManageAssignment($request->user(), $assignment)) {
            abort(403, 'Anda hanya boleh mengelola tugas milik sendiri.');
        }
    }
}
