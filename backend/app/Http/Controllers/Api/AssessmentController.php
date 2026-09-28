<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Assessment\AssessmentRequest;
use App\Http\Resources\AssessmentResource;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\ClassStudent;
use App\Models\ClassSubject;
use App\Models\Employee;
use App\Models\PassingGradeRule;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Remedial;
use App\Models\Score;
use App\Models\Semester;
use App\Models\Student;
use App\Services\Assessment\GradingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Penilaian / assessment (A7).
 *
 * Menjawab: "penilaian apa yang dijalankan kelas ini, sudah lengkap nilainya
 * belum, dan sampai tahap mana prosesnya?"
 *
 * Aturan inti:
 * - Mapel harus benar-benar diajarkan di kelas tersebut pada tahun ajaran terkait.
 * - Tanggal penilaian harus berada di dalam rentang semesternya.
 * - Kelas/mapel/semester tidak boleh diubah setelah ada skor tercatat.
 * - Setelah finalisasi nilai terkunci: tidak bisa diubah atau dihapus sebelum
 *   admin membukanya kembali beserta alasan.
 */
class AssessmentController extends BaseApiController
{
    /** Nama filter di API => nama kolom di tabel. */
    protected array $filters = [
        'class_id' => 'school_class_id',
        'subject_id' => 'subject_id',
        'semester_id' => 'semester_id',
        'assessment_type_id' => 'assessment_type_id',
        'teacher_id' => 'employee_id',
        'status' => 'status',
    ];

    /** Relasi standar agar frontend tidak perlu request tambahan. */
    protected array $with = ['schoolClass', 'subject', 'semester', 'teacher', 'assessmentType'];

    public function __construct(protected GradingService $grading) {}

    /** Lookups khusus form assessment; rute dibatasi ke pemegang assessment.create. */
    public function lookups(): JsonResponse
    {
        return $this->successResponse([
            'classes' => SchoolClass::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'academic_year_id']),
            'semesters' => Semester::query()->with('academicYear:id,name')->orderByDesc('start_date')->get(['id', 'academic_year_id', 'name', 'start_date', 'end_date', 'is_active']),
            'types' => AssessmentType::query()->active()->ordered()->get(['id', 'code', 'name', 'category']),
            'teachers' => Employee::query()->where('is_teacher', true)->where('is_active', true)->orderBy('full_name')->get(['id', 'nip', 'full_name']),
        ], 'Referensi penilaian berhasil diambil');
    }

    public function index(Request $request): JsonResponse
    {
        $query = Assessment::query()
            ->with($this->with)
            ->withCount($this->counts());

        foreach ((array) $request->query('filter', []) as $key => $value) {
            if ($value === null || $value === '' || ! isset($this->filters[$key])) {
                continue;
            }
            $query->where($this->filters[$key], $value);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('date', '<=', $to);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $query->orderByDesc('date')->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                AssessmentResource::collection($query->get()),
                'Daftar penilaian berhasil diambil',
            );
        }

        return $this->successResponse(
            AssessmentResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar penilaian berhasil diambil',
        );
    }

    public function show(Assessment $assessment): JsonResponse
    {
        return $this->successResponse(
            new AssessmentResource($assessment->load($this->with)->loadCount($this->counts())),
            'Detail penilaian berhasil diambil',
        );
    }

    public function store(AssessmentRequest $request): JsonResponse
    {
        $data = $request->payload();

        $this->assertDateInSemester((int) $data['semester_id'], $data['date'] ?? null);
        $classSubject = $this->assertSubjectTaught(
            (int) $data['school_class_id'],
            (int) $data['subject_id'],
            (int) $data['semester_id'],
        );

        // Guru pengampu mapel menjadi default bila guru tidak dipilih eksplisit.
        $data['employee_id'] = $data['employee_id'] ?? $classSubject->employee_id;
        $data['status'] = Assessment::STATUS_DRAFT;
        $data['max_score'] = $data['max_score'] ?? 100;

        $assessment = Assessment::create($data);

        return $this->createdResponse(
            new AssessmentResource($assessment->load($this->with)),
            'Penilaian berhasil dibuat',
        );
    }

    public function update(AssessmentRequest $request, Assessment $assessment): JsonResponse
    {
        $this->assertNotLocked($assessment);

        $data = $request->payload();

        $this->assertDateInSemester(
            (int) ($data['semester_id'] ?? $assessment->semester_id),
            $data['date'] ?? $assessment->date?->toDateString(),
        );

        $this->assertContextUnchanged($assessment, $data);
        $this->assertSubjectTaught(
            (int) ($data['school_class_id'] ?? $assessment->school_class_id),
            (int) ($data['subject_id'] ?? $assessment->subject_id),
            (int) ($data['semester_id'] ?? $assessment->semester_id),
        );

        $assessment->update($data);

        return $this->successResponse(
            new AssessmentResource($assessment->refresh()->load($this->with)),
            'Penilaian berhasil diperbarui',
        );
    }

    public function destroy(Assessment $assessment): JsonResponse
    {
        $this->assertNotLocked($assessment);

        $assessment->delete();

        return $this->successResponse(null, 'Penilaian berhasil dihapus');
    }

    /**
     * Lembar nilai: daftar siswa kelas ini beserta skor yang sudah tercatat.
     *
     * Siswa yang belum dinilai tetap muncul agar guru tahu siapa yang belum
     * lengkap, tanpa perlu mencari satu per satu.
     */
    public function sheet(Assessment $assessment): JsonResponse
    {
        $assessment->load(['schoolClass', 'subject', 'semester', 'assessmentType']);

        $scores = Score::query()
            ->where('assessment_id', $assessment->id)
            ->get()
            ->keyBy('student_id');

        $remedials = Remedial::query()
            ->where('assessment_id', $assessment->id)
            ->get()
            ->keyBy('student_id');

        $rule = PassingGradeRule::resolveFor(
            $assessment->subject_id,
            $assessment->schoolClass?->education_level_id,
            $assessment->semester?->academic_year_id,
        );

        $items = $this->studentsOf($assessment)->map(function (Student $student) use ($assessment, $scores, $remedials) {
            $score = $scores->get($student->id);
            $remedial = $remedials->get($student->id);

            return [
                'student_id' => $student->id,
                'nis' => $student->nis,
                'full_name' => $student->full_name,
                'score_id' => $score?->id,
                'score' => $score?->score === null ? null : (float) $score->score,
                'grade' => $score?->grade,
                'notes' => $score?->notes,
                'remedial_id' => $remedial?->id,
                'remedial_score_after' => $remedial?->score_after === null ? null : (float) $remedial->score_after,
                'graded' => $score?->score !== null,
            ];
        })->values();

        return $this->successResponse([
            'assessment' => new AssessmentResource($assessment),
            'max_score' => $assessment->maxScoreValue(),
            'passing_grade' => $rule?->min_score === null ? null : (float) $rule->min_score,
            'is_locked' => $assessment->isLocked(),
            'total' => $items->count(),
            'graded' => $items->where('graded', true)->count(),
            'pending' => $items->where('graded', false)->count(),
            'items' => $items->all(),
        ], 'Lembar nilai berhasil diambil');
    }

    /**
     * Statistik satu penilaian: rata-rata, sebaran, dan siswa di bawah KKTP.
     */
    public function stats(Assessment $assessment): JsonResponse
    {
        return $this->successResponse(
            $this->grading->summaryFor($assessment),
            'Statistik penilaian berhasil diambil',
        );
    }

    /**
     * Serahkan nilai ke koordinator/wali kelas. Semua siswa harus sudah dinilai,
     * kecuali pemakai yang berwenang memaksa dengan alasan.
     */
    public function submit(Request $request, Assessment $assessment): JsonResponse
    {
        if ($assessment->status !== Assessment::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => "Penilaian ini sudah berstatus {$assessment->status} dan tidak perlu diserahkan lagi.",
            ]);
        }

        $data = $this->validateTransition($request);
        $this->assertComplete($assessment, $data);

        $assessment->update(['status' => Assessment::STATUS_SUBMITTED]);

        return $this->successResponse(
            new AssessmentResource($assessment->refresh()->load($this->with)),
            'Nilai berhasil diserahkan untuk diverifikasi',
        );
    }

    /**
     * Verifikasi oleh wali kelas / koordinator akademik.
     */
    public function verify(Assessment $assessment): JsonResponse
    {
        $this->assertStatus(
            $assessment,
            [Assessment::STATUS_SUBMITTED],
            'Nilai hanya bisa diverifikasi setelah diserahkan guru.',
        );

        $assessment->update(['status' => Assessment::STATUS_VERIFIED]);

        return $this->successResponse(
            new AssessmentResource($assessment->refresh()->load($this->with)),
            'Nilai berhasil diverifikasi',
        );
    }

    /**
     * Finalisasi: nilai terkunci dan siap dipakai rapor.
     */
    public function finalize(Request $request, Assessment $assessment): JsonResponse
    {
        $this->assertStatus(
            $assessment,
            [Assessment::STATUS_SUBMITTED, Assessment::STATUS_VERIFIED],
            'Nilai hanya bisa difinalisasi setelah diserahkan (verifikasi opsional).',
        );

        $data = $this->validateTransition($request);
        $this->assertComplete($assessment, $data);

        $assessment->update(['status' => Assessment::STATUS_FINALIZED]);

        return $this->successResponse(
            new AssessmentResource($assessment->refresh()->load($this->with)),
            'Nilai berhasil difinalisasi dan dikunci',
        );
    }

    /**
     * Buka kembali nilai yang sudah terkunci untuk perbaikan.
     *
     * Tidak ada nilai yang diubah di sini — hanya statusnya. Setelah dibuka,
     * setiap perubahan skor wajib disertai alasan dan tercatat di `score_revisions`.
     */
    public function reopen(Request $request, Assessment $assessment): JsonResponse
    {
        $this->assertStatus(
            $assessment,
            [Assessment::STATUS_VERIFIED, Assessment::STATUS_FINALIZED],
            'Hanya nilai yang sudah diverifikasi atau difinalisasi yang bisa dibuka kembali.',
        );

        $data = $this->validateTransition($request, requireReason: true);

        $assessment->update([
            'status' => Assessment::STATUS_REVISION_REQUESTED,
            'extra' => array_merge($assessment->extra ?? [], [
                'reopened' => [
                    'at' => now()->toIso8601String(),
                    'by' => $request->user()?->id,
                    'reason' => $data['reason'],
                    'from_status' => $assessment->status,
                ],
            ]),
        ]);

        return $this->successResponse(
            new AssessmentResource($assessment->refresh()->load($this->with)),
            'Nilai berhasil dibuka kembali untuk revisi',
        );
    }

    // -----------------------------------------------------------------
    // Pemeriksaan aturan bisnis
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function validateTransition(Request $request, bool $requireReason = false): array
    {
        return $request->validate([
            'force' => ['boolean'],
            'reason' => [$requireReason ? 'required' : 'nullable', 'string', 'max:255'],
        ], [], [
            'reason' => 'alasan',
            'force' => 'paksa',
        ]);
    }

    /**
     * Skor tidak boleh diubah atau dihapus setelah finalisasi.
     */
    protected function assertNotLocked(Assessment $assessment): void
    {
        if ($assessment->isLocked()) {
            throw ValidationException::withMessages([
                'status' => 'Penilaian ini sudah difinalisasi dan terkunci. Buka kembali nilai untuk memperbaikinya.',
            ]);
        }
    }

    /**
     * Transisi status hanya boleh dilakukan dari status yang sah.
     *
     * @param  list<string>  $allowed
     */
    protected function assertStatus(Assessment $assessment, array $allowed, string $message): void
    {
        if (! in_array($assessment->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => $message,
            ]);
        }
    }

    /**
     * Semua siswa kelas ini harus sudah dinilai.
     *
     * @param  array{force?: bool, reason?: string|null}  $data
     */
    protected function assertComplete(Assessment $assessment, array $data): void
    {
        $pending = $this->studentsOf($assessment)
            ->whereNotIn('id', Score::query()
                ->where('assessment_id', $assessment->id)
                ->whereNotNull('score')
                ->pluck('student_id')
                ->all())
            ->count();

        if ($pending === 0) {
            return;
        }

        if (! ($data['force'] ?? false)) {
            throw ValidationException::withMessages([
                'score' => "Masih ada {$pending} siswa yang belum dinilai. Lengkapi nilai atau gunakan paksa dengan alasan.",
            ]);
        }

        if (blank($data['reason'] ?? null)) {
            throw ValidationException::withMessages([
                'reason' => 'Alasan wajib diisi untuk menyerahkan nilai yang belum lengkap.',
            ]);
        }
    }

    /**
     * Mapel harus terdaftar di kelas ini pada tahun ajaran semester tersebut.
     */
    protected function assertSubjectTaught(int $classId, int $subjectId, int $semesterId): ClassSubject
    {
        $semester = Semester::findOrFail($semesterId);

        $classSubject = ClassSubject::query()
            ->where('school_class_id', $classId)
            ->where('subject_id', $subjectId)
            ->where('academic_year_id', $semester->academic_year_id)
            ->first();

        if ($classSubject === null) {
            throw ValidationException::withMessages([
                'subject_id' => 'Mata pelajaran ini belum diampu di kelas tersebut pada tahun ajaran semester yang dipilih.',
            ]);
        }

        return $classSubject;
    }

    /**
     * Konteks kelas/mapel/semester tidak boleh berubah setelah ada skor.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertContextUnchanged(Assessment $assessment, array $data): void
    {
        if (! $assessment->scores()->exists()) {
            return;
        }

        $changes = array_filter([
            'class_id' => isset($data['school_class_id']) && $data['school_class_id'] !== $assessment->school_class_id,
            'subject_id' => isset($data['subject_id']) && $data['subject_id'] !== $assessment->subject_id,
            'semester_id' => isset($data['semester_id']) && $data['semester_id'] !== $assessment->semester_id,
        ]);

        if ($changes !== []) {
            throw ValidationException::withMessages([
                'subject_id' => 'Kelas, mata pelajaran, dan semester tidak boleh diubah karena nilai siswa sudah tercatat. Hapus dulu seluruh nilainya.',
            ]);
        }
    }

    protected function assertDateInSemester(int $semesterId, ?string $date): void
    {
        if (! $date) {
            return;
        }

        $semester = Semester::find($semesterId);

        if (! $semester) {
            return;
        }

        $value = Carbon::parse($date)->toDateString();

        $outside = ($semester->start_date && $value < $semester->start_date->toDateString())
            || ($semester->end_date && $value > $semester->end_date->toDateString());

        if ($outside) {
            throw ValidationException::withMessages([
                'date' => 'Tanggal penilaian harus berada di dalam rentang semester yang dipilih.',
            ]);
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, Student>
     */
    protected function studentsOf(Assessment $assessment)
    {
        return Student::query()
            ->whereIn('id', ClassStudent::query()
                ->where('school_class_id', $assessment->school_class_id)
                ->where('status', 'aktif')
                ->pluck('student_id'))
            ->orderBy('full_name')
            ->get();
    }

    /**
     * @return array<int, string|callable>
     */
    protected function counts(): array
    {
        return [
            'scores',
            'scores as graded_count' => fn ($q) => $q->whereNotNull('score'),
            'remedials',
        ];
    }
}
