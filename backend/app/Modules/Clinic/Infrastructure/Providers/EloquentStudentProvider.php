<?php

namespace App\Modules\Clinic\Infrastructure\Providers;

use App\Models\ClassStudent;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Modules\Clinic\Domain\Contracts\StudentProviderInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sumber data siswa dari master ERP.
 *
 * Kelas/nama/program selalu dibaca dari `students`, `class_students`, dan
 * `school_classes`. Tidak ada penyalinan ke tabel Clinic — inilah alasan
 * nomor rekam medis terpisah dari nomor induk siswa: MRN menempel pada pasien,
 * sedangkan kelas berubah setiap tahun.
 */
class EloquentStudentProvider implements StudentProviderInterface
{
    public function snapshot(int $studentId): ?array
    {
        $student = Student::query()
            ->with(['gender:id,name', 'program:id,name'])
            ->find($studentId);

        if ($student === null) {
            return null;
        }

        $class = $this->currentClassFor($studentId);

        return [
            'id' => (int) $student->id,
            'nis' => $student->nis,
            'nisn' => $student->nisn,
            'name' => (string) $student->full_name,
            'nick_name' => $student->nick_name,
            'gender' => $student->gender?->name,
            'birth_date' => $student->birth_date?->toDateString(),
            'age_years' => $student->birth_date === null ? null : (int) $student->birth_date->age,
            'blood_type' => $student->blood_type,
            'class_id' => $class['id'] ?? null,
            'class_name' => $class['name'] ?? null,
            'program' => $student->program?->name,
            'is_active' => (bool) $student->is_active,
        ];
    }

    public function search(string $term, int $limit = 20): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $like = '%'.$term.'%';

        return Student::query()
            ->where(fn ($q) => $q->where('full_name', 'like', $like)
                ->orWhere('nis', 'like', $like)
                ->orWhere('nisn', 'like', $like))
            ->orderBy('full_name')
            ->limit($limit)
            ->get(['id', 'nis', 'nisn', 'full_name'])
            ->map(function (Student $student): array {
                $class = $this->currentClassFor((int) $student->id);

                return [
                    'id' => (int) $student->id,
                    'nis' => $student->nis,
                    'name' => (string) $student->full_name,
                    'class_name' => $class['name'] ?? null,
                ];
            })
            ->all();
    }

    public function snapshots(array $studentIds): Collection
    {
        return collect($studentIds)
            ->unique()
            ->mapWithKeys(fn (int $id): array => [$id => $this->snapshot($id)]);
    }

    public function studentIdsInClass(int $schoolClassId): array
    {
        return ClassStudent::query()
            ->where('school_class_id', $schoolClassId)
            ->where('status', 'aktif')
            ->pluck('student_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function activeStudentIds(?int $educationLevelId = null): array
    {
        $activeYearIds = DB::table('academic_years')->where('is_active', true)->pluck('id');

        if ($activeYearIds->isEmpty()) {
            return [];
        }

        $classIds = SchoolClass::query()
            ->whereIn('academic_year_id', $activeYearIds)
            ->where('is_active', true)
            ->when($educationLevelId !== null, fn ($q) => $q->where('education_level_id', $educationLevelId))
            ->pluck('id');

        if ($classIds->isEmpty()) {
            return [];
        }

        return ClassStudent::query()
            ->whereIn('school_class_id', $classIds)
            ->where('status', 'aktif')
            ->pluck('student_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array{id: int, name: string}|null */
    private function currentClassFor(int $studentId): ?array
    {
        $row = DB::table('class_students')
            ->join('school_classes', 'school_classes.id', '=', 'class_students.school_class_id')
            ->join('academic_years', 'academic_years.id', '=', 'class_students.academic_year_id')
            ->where('class_students.student_id', $studentId)
            ->where('class_students.status', 'aktif')
            ->orderByDesc('academic_years.is_active')
            ->orderByDesc('academic_years.start_date')
            ->first(['school_classes.id', 'school_classes.name']);

        return $row === null ? null : ['id' => (int) $row->id, 'name' => (string) $row->name];
    }
}
