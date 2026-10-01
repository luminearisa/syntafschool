<?php

namespace App\Modules\Activity\Infrastructure\Providers;

use App\Models\ClassStudent;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Modules\Activity\Domain\Contracts\StudentProviderInterface;

/**
 * Identitas siswa dari master ERP.
 *
 * Kelas dibaca dari `class_students` → `school_classes` karena keanggotaan
 * kelas berubah setiap tahun, sedangkan identitas siswa tidak. Tidak ada
 * penyalinan nama/kelas ke tabel `activity_*`.
 */
class EloquentStudentProvider implements StudentProviderInterface
{
    public function snapshot(int $studentId): ?array
    {
        $student = Student::query()->with(['gender:id,name', 'program:id,name'])->find($studentId);

        if ($student === null) {
            return null;
        }

        $class = $this->currentClass($studentId);

        return [
            'id' => (int) $student->id,
            'nis' => $student->nis,
            'nisn' => $student->nisn,
            'name' => (string) $student->full_name,
            'nick_name' => $student->nick_name,
            'gender' => $student->gender?->name,
            'birth_date' => $student->birth_date?->toDateString(),
            'age_years' => $student->birth_date === null ? null : (int) $student->birth_date->age,
            'class_id' => $class['id'] ?? null,
            'class_name' => $class['name'] ?? null,
            'class_level' => $class['level'] ?? null,
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
            ->where('is_active', true)
            ->where(function ($query) use ($like) {
                $query->where('full_name', 'like', $like)->orWhere('nis', 'like', $like);
            })
            ->limit($limit)
            ->get()
            ->map(fn (Student $student) => [
                'id' => (int) $student->id,
                'name' => (string) $student->full_name,
                'nis' => $student->nis,
                'class_name' => $this->currentClass((int) $student->id)['name'] ?? null,
            ])
            ->all();
    }

    public function currentClass(int $studentId): ?array
    {
        $row = ClassStudent::query()
            ->where('student_id', $studentId)
            ->latest('academic_year_id')
            ->first();

        if ($row === null) {
            return null;
        }

        $class = SchoolClass::query()->with('educationLevel:id,name')->find($row->school_class_id);

        if ($class === null) {
            return null;
        }

        return [
            'id' => (int) $class->id,
            'name' => (string) $class->name,
            'code' => $class->code,
            'level' => $this->levelFromName((string) $class->name),
            'education_level' => $class->educationLevel?->name,
        ];
    }

    public function snapshots(array $studentIds): array
    {
        $ids = array_values(array_unique(array_filter($studentIds, fn ($id) => (int) $id > 0)));

        if ($ids === []) {
            return [];
        }

        return Student::query()
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn (Student $student) => [(int) $student->id => [
                'id' => (int) $student->id,
                'name' => (string) $student->full_name,
                'nis' => $student->nis,
                'class_name' => $this->currentClass((int) $student->id)['name'] ?? null,
            ]])
            ->all();
    }

    /** Jenjang dari nama kelas (mis. "VII A" → 7) untuk aturan GRADE. */
    protected function levelFromName(string $name): ?int
    {
        $roman = ['VII' => 7, 'VIII' => 8, 'IX' => 9, 'X' => 10, 'XI' => 11, 'XII' => 12];

        foreach ($roman as $token => $level) {
            if (preg_match('/\b'.$token.'\b/i', $name) === 1) {
                return $level;
            }
        }

        return preg_match('/(\d{1,2})/', $name, $match) === 1 ? (int) $match[1] : null;
    }
}
