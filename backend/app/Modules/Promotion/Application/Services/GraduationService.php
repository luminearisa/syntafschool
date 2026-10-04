<?php

namespace App\Modules\Promotion\Application\Services;

use App\Models\AcademicYear;
use App\Models\ClassStudent;
use App\Models\EducationLevel;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentStatus;
use App\Modules\Promotion\Models\Graduation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mesin kelulusan (A10).
 *
 * Menilai siswa pada tingkat akhir suatu tahun ajaran terhadap `promotion_rules`,
 * menyimpan keputusan kelulusan beserta potret kriterianya, lalu menerbitkan
 * nomor kelulusan sekali saja saat disetujui.
 *
 * Perhitungan kriteria memakai kembali `PromotionService` supaya definisi
 * "nilai akhir", "kehadiran", dan "poin pelanggaran" hanya ada di satu tempat.
 */
class GraduationService
{
    /** Segmen nomor kelulusan per tahun ajaran, mis. SKL/2026-2027/0001. */
    private const NUMBER_PREFIX = 'SKL';

    public function __construct(private readonly PromotionService $promotion) {}

    // -----------------------------------------------------------------
    // Evaluasi
    // -----------------------------------------------------------------

    /**
     * Tingkat akhir = jenjang dengan `level_number` tertinggi yang punya kelas
     * pada tahun ajaran tersebut.
     */
    public function finalEducationLevelId(int $academicYearId): ?int
    {
        $levelIds = SchoolClass::query()
            ->where('academic_year_id', $academicYearId)
            ->whereNotNull('education_level_id')
            ->pluck('education_level_id');

        if ($levelIds->isEmpty()) {
            return null;
        }

        return EducationLevel::query()
            ->whereIn('id', $levelIds)
            ->orderByDesc('level_number')
            ->value('id');
    }

    /**
     * Pratinjau kelulusan untuk siswa tingkat akhir, tanpa menyimpan.
     *
     * @return array<string, mixed>
     */
    public function evaluate(
        int $academicYearId,
        ?int $educationLevelId = null,
        ?int $classId = null,
        ?int $semesterId = null,
    ): array {
        $classes = $this->targetClasses($academicYearId, $educationLevelId, $classId);

        if ($classes->isEmpty()) {
            throw ValidationException::withMessages([
                'academic_year_id' => 'Tidak ada kelas tingkat akhir pada tahun ajaran ini.',
            ]);
        }

        $rows = collect();

        foreach ($classes as $class) {
            $rule = $this->promotion->requireRule($class);

            foreach ($this->promotion->activeStudents($class) as $student) {
                $snapshot = $this->promotion->buildSnapshot($class, $student, $rule, $semesterId);

                $existing = Graduation::query()
                    ->where('student_id', $student->id)
                    ->where('academic_year_id', $academicYearId)
                    ->first();

                $rows->push([
                    'student' => [
                        'id' => (int) $student->id,
                        'nis' => $student->nis,
                        'full_name' => $student->full_name,
                    ],
                    'class' => [
                        'id' => (int) $class->id,
                        'name' => $class->name,
                    ],
                    'snapshot' => $snapshot,
                    'average_score' => $snapshot['average_score'],
                    'suggested_decision' => $snapshot['passed_all']
                        ? Graduation::DECISION_LULUS
                        : Graduation::DECISION_TIDAK_LULUS,
                    'existing_graduation_id' => $existing?->id,
                ]);
            }
        }

        return [
            'academic_year_id' => $academicYearId,
            'education_level_id' => $educationLevelId,
            'semester_id' => $semesterId,
            'class_count' => $classes->count(),
            'student_count' => $rows->count(),
            'lulus_count' => $rows->where('suggested_decision', Graduation::DECISION_LULUS)->count(),
            'tidak_lulus_count' => $rows->where('suggested_decision', Graduation::DECISION_TIDAK_LULUS)->count(),
            'students' => $rows->values()->all(),
        ];
    }

    // -----------------------------------------------------------------
    // Penyimpanan
    // -----------------------------------------------------------------

    /**
     * Simpan keputusan kelulusan massal.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, Graduation>
     */
    public function bulk(int $academicYearId, array $rows): Collection
    {
        return DB::transaction(function () use ($academicYearId, $rows): Collection {
            $saved = collect();

            foreach ($rows as $row) {
                $student = Student::query()->find((int) ($row['student_id'] ?? 0));

                if ($student === null) {
                    throw ValidationException::withMessages([
                        'graduations' => 'Siswa pada daftar kelulusan tidak ditemukan.',
                    ]);
                }

                $exists = Graduation::withTrashed()
                    ->where('student_id', $student->id)
                    ->where('academic_year_id', $academicYearId)
                    ->exists();

                if ($exists) {
                    throw ValidationException::withMessages([
                        'graduations' => "Keputusan kelulusan {$student->full_name} pada tahun ajaran ini sudah ada.",
                    ]);
                }

                $class = $this->resolveClass($academicYearId, $student, $row['school_class_id'] ?? null);
                $rule = $this->promotion->requireRule($class);
                $snapshot = $this->promotion->buildSnapshot($class, $student, $rule, isset($row['semester_id']) ? (int) $row['semester_id'] : null);

                $graduation = Graduation::create([
                    'student_id' => $student->id,
                    'academic_year_id' => $academicYearId,
                    'decision' => $row['decision'] ?? null,
                    'average_score' => $snapshot['average_score'],
                    'criteria_summary' => $snapshot,
                    'notes' => $row['notes'] ?? null,
                ]);

                $saved->push($graduation);
            }

            return $saved->map(fn (Graduation $graduation) => $graduation->fresh(['student', 'academicYear', 'approvedBy']));
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Graduation $graduation, array $data): Graduation
    {
        if ($graduation->isApproved()) {
            throw ValidationException::withMessages([
                'status' => 'Kelulusan yang sudah disetujui tidak dapat diubah.',
            ]);
        }

        $graduation->update($data);

        return $graduation->fresh(['student', 'academicYear', 'approvedBy']);
    }

    // -----------------------------------------------------------------
    // Persetujuan & nomor kelulusan
    // -----------------------------------------------------------------

    /**
     * Setujui kelulusan, terbitkan nomor (sekali saja), dan tandai status siswa.
     *
     * Idempoten: menyetujui ulang tidak menerbitkan nomor baru.
     */
    public function approve(Graduation $graduation, ?int $userId): Graduation
    {
        DB::transaction(function () use ($graduation, $userId): void {
            $attributes = [
                'approved_by' => $userId,
                'approved_at' => $graduation->approved_at ?? now(),
            ];

            if (empty($graduation->graduation_number)) {
                $attributes['graduation_number'] = $this->generateNumber((int) $graduation->academic_year_id);
            }

            $graduation->update($attributes);
        });

        $graduation = $graduation->fresh();

        $this->markStudentStatus($graduation);

        return $graduation->fresh(['student', 'academicYear', 'approvedBy']);
    }

    /**
     * Terbitkan nomor kelulusan unik berurutan per tahun ajaran.
     */
    public function generateNumber(int $academicYearId): string
    {
        $year = AcademicYear::query()->find($academicYearId);
        $label = $year !== null ? str_replace('/', '-', $year->name) : date('Y');

        $sequence = Graduation::query()
            ->where('academic_year_id', $academicYearId)
            ->whereNotNull('graduation_number')
            ->count() + 1;

        do {
            $number = sprintf('%s/%s/%04d', self::NUMBER_PREFIX, $label, $sequence);
            $sequence++;
        } while (Graduation::withTrashed()->where('graduation_number', $number)->exists());

        return $number;
    }

    /**
     * Tandai status siswa sesuai keputusan kelulusan.
     *
     * `lulus` → status "Lulus" dan siswa dinonaktifkan; `tidak_lulus` → "Tinggal Kelas".
     * Bila master status tidak memuat namanya, status_id tidak diubah.
     */
    public function markStudentStatus(Graduation $graduation): void
    {
        $student = Student::query()->find($graduation->student_id);

        if ($student === null) {
            return;
        }

        $statusName = $graduation->decision === Graduation::DECISION_LULUS ? 'Lulus' : 'Tinggal Kelas';
        $statusId = StudentStatus::query()->where('name', $statusName)->value('id');

        $attributes = [];
        if ($statusId !== null) {
            $attributes['status_id'] = $statusId;
        }

        if ($graduation->decision === Graduation::DECISION_LULUS) {
            $attributes['is_active'] = false;
        }

        if ($attributes !== []) {
            $student->update($attributes);
        }
    }

    // -----------------------------------------------------------------
    // Utilitas
    // -----------------------------------------------------------------

    /**
     * @return Collection<int, SchoolClass>
     */
    private function targetClasses(int $academicYearId, ?int $educationLevelId, ?int $classId): Collection
    {
        $query = SchoolClass::query()
            ->where('academic_year_id', $academicYearId)
            ->where('is_active', true);

        if ($classId !== null) {
            return $query->whereKey($classId)->get();
        }

        $levelId = $educationLevelId ?? $this->finalEducationLevelId($academicYearId);

        if ($levelId === null) {
            return collect();
        }

        return $query->where('education_level_id', $levelId)->orderBy('name')->get();
    }

    private function resolveClass(int $academicYearId, Student $student, ?int $classId): SchoolClass
    {
        if ($classId !== null) {
            $class = SchoolClass::query()->find($classId);
            if ($class === null) {
                throw ValidationException::withMessages(['school_class_id' => 'Kelas tidak ditemukan.']);
            }

            return $class;
        }

        $resolved = ClassStudent::query()
            ->where('student_id', $student->id)
            ->where('academic_year_id', $academicYearId)
            ->first();

        $class = $resolved !== null ? SchoolClass::query()->find($resolved->school_class_id) : null;

        if ($class === null) {
            throw ValidationException::withMessages([
                'graduations' => "Kelas untuk {$student->full_name} tidak ditemukan.",
            ]);
        }

        return $class;
    }
}
