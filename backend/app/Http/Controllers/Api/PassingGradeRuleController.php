<?php

namespace App\Http\Controllers\Api;

use App\Models\PassingGradeRule;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * KKM / KKTP — batas tuntas (A7 — konfigurasi).
 *
 * Angka batasnya tidak di-hardcode di mana pun: aturan dibuat per mata pelajaran,
 * per jenjang, atau per tahun ajaran, dan yang paling spesifik yang dipakai.
 */
class PassingGradeRuleController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = PassingGradeRule::query()
            ->with(['subject', 'educationLevel', 'academicYear']);

        foreach (['subject_id', 'education_level_id', 'academic_year_id'] as $key) {
            if ($value = $request->query("filter.{$key}", $request->query($key))) {
                $query->where($key, $value);
            }
        }

        if ($request->boolean('general_only')) {
            $query->whereNull('subject_id');
        }

        $query->orderBy('subject_id')->orderBy('id');

        if ($request->boolean('all')) {
            return $this->successResponse($query->get(), 'Aturan KKM berhasil diambil');
        }

        return $this->successResponse(
            $query->paginate((int) $request->query('per_page', 15)),
            'Aturan KKM berhasil diambil',
        );
    }

    /**
     * Lihat KKTP yang sebenarnya berlaku untuk satu kelas + mapel.
     */
    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'semester_id' => ['nullable', 'integer', 'exists:semesters,id'],
        ], [], [
            'class_id' => 'kelas',
            'subject_id' => 'mata pelajaran',
            'semester_id' => 'semester',
        ]);

        $class = SchoolClass::with('educationLevel')->findOrFail($data['class_id']);
        $academicYearId = $this->academicYearId($data['semester_id'] ?? null, $class);

        $rule = PassingGradeRule::resolveFor(
            (int) $data['subject_id'],
            $class->education_level_id,
            $academicYearId,
        );

        return $this->successResponse([
            'class_id' => $class->id,
            'subject_id' => (int) $data['subject_id'],
            'min_score' => $rule?->min_score === null ? null : (float) $rule->min_score,
            'source' => $rule === null ? null : $this->describe($rule),
            'rule' => $rule,
        ], 'KKTP berhasil ditentukan');
    }

    public function show(PassingGradeRule $rule): JsonResponse
    {
        return $this->successResponse(
            $rule->load(['subject', 'educationLevel', 'academicYear']),
            'Detail aturan KKM berhasil diambil',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        $this->assertNotDuplicated($data);

        $rule = PassingGradeRule::create($data);

        return $this->createdResponse(
            $rule->load(['subject', 'educationLevel', 'academicYear']),
            'Aturan KKM berhasil dibuat',
        );
    }

    public function update(Request $request, PassingGradeRule $rule): JsonResponse
    {
        $data = $this->validateData($request);

        $this->assertNotDuplicated($data, $rule);

        $rule->update($data);

        return $this->successResponse(
            $rule->refresh()->load(['subject', 'educationLevel', 'academicYear']),
            'Aturan KKM berhasil diperbarui',
        );
    }

    public function destroy(PassingGradeRule $rule): JsonResponse
    {
        $rule->delete();

        return $this->successResponse(null, 'Aturan KKM berhasil dihapus');
    }

    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function validateData(Request $request): array
    {
        return $request->validate([
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'education_level_id' => ['nullable', 'integer', 'exists:education_levels,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'min_score' => ['required', 'numeric', 'between:0,100'],
        ], [], [
            'subject_id' => 'mata pelajaran',
            'education_level_id' => 'jenjang',
            'academic_year_id' => 'tahun ajaran',
            'min_score' => 'KKM',
        ]);
    }

    /**
     * Dua aturan dengan konteks sama akan membuat hasil perhitungan tergantung
     * urutan baris di database — lebih baik ditolak sejak awal.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertNotDuplicated(array $data, ?PassingGradeRule $ignore = null): void
    {
        $duplicate = PassingGradeRule::query()
            ->when(
                $data['subject_id'] ?? null,
                fn ($q, $value) => $q->where('subject_id', $value),
                fn ($q) => $q->whereNull('subject_id'),
            )
            ->when(
                $data['education_level_id'] ?? null,
                fn ($q, $value) => $q->where('education_level_id', $value),
                fn ($q) => $q->whereNull('education_level_id'),
            )
            ->when(
                $data['academic_year_id'] ?? null,
                fn ($q, $value) => $q->where('academic_year_id', $value),
                fn ($q) => $q->whereNull('academic_year_id'),
            )
            ->when($ignore, fn ($q) => $q->where('id', '!=', $ignore->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'subject_id' => 'Aturan KKM untuk kombinasi mata pelajaran, jenjang, dan tahun ajaran ini sudah ada.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function describe(PassingGradeRule $rule): array
    {
        return [
            'id' => $rule->id,
            'scope' => match (true) {
                $rule->subject_id !== null => 'mata_pelajaran',
                $rule->education_level_id !== null => 'jenjang',
                default => 'umum_sekolah',
            },
            'label' => trim(collect([
                $rule->subject?->name,
                $rule->educationLevel?->name,
                $rule->academicYear?->name,
            ])->filter()->implode(' · ')) ?: 'Semua mapel',
        ];
    }

    protected function academicYearId(?int $semesterId, SchoolClass $class): ?int
    {
        if ($semesterId) {
            return Semester::query()->findOrFail($semesterId)->academic_year_id;
        }

        return $class->academic_year_id;
    }
}
