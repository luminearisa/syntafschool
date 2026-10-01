<?php

namespace App\Modules\Bk\Infrastructure\Providers;

use App\Models\Student;
use App\Modules\Bk\Domain\Contracts\StudentProviderInterface;
use Illuminate\Support\Facades\DB;

/**
 * Identitas siswa dari master ERP.
 *
 * Kelas, wali kelas, dan status selalu dibaca dari `students` +
 * `class_students` + `school_classes`; tidak ada penyalinan ke tabel BK.
 */
class EloquentStudentProvider implements StudentProviderInterface
{
    public function snapshot(int $studentId): ?array
    {
        $student = Student::query()->with(['gender:id,name'])->find($studentId);

        if ($student === null) {
            return null;
        }

        $class = $this->currentClassFor($studentId);

        return [
            'id' => (int) $student->id,
            'nis' => $student->nis,
            'nisn' => $student->nisn,
            'name' => (string) $student->full_name,
            'gender' => $student->gender?->name,
            'birth_date' => $student->birth_date?->toDateString(),
            'age_years' => $student->birth_date === null ? null : (int) $student->birth_date->age,
            'class_id' => $class['id'] ?? null,
            'class_name' => $class['name'] ?? null,
            'homeroom_teacher_id' => $class['homeroom_teacher_id'] ?? null,
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
            ->get(['id', 'nis', 'full_name'])
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

    /**
     * Kelas aktif siswa saat ini (termasuk wali kelas) — dipakai juga oleh
     * `BkNotificationService` untuk menjangkau wali kelas tanpa pencarian ulang.
     *
     * @return array{id: int, name: string, homeroom_teacher_id: ?int}|null
     */
    private function currentClassFor(int $studentId): ?array
    {
        $row = DB::table('class_students')
            ->join('school_classes', 'school_classes.id', '=', 'class_students.school_class_id')
            ->where('class_students.student_id', $studentId)
            ->where('class_students.status', 'aktif')
            ->orderByDesc('class_students.id')
            ->first(['school_classes.id', 'school_classes.name', 'school_classes.homeroom_teacher_id']);

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'homeroom_teacher_id' => $row->homeroom_teacher_id === null ? null : (int) $row->homeroom_teacher_id,
        ];
    }
}
