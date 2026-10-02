<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\StudentGatewayInterface;
use Illuminate\Support\Facades\DB;

/**
 * Siswa dibaca dari modul Student/Academic. Tidak ada master siswa kedua.
 */
class EloquentStudentGateway implements StudentGatewayInterface
{
    use GatewaySupport;

    public function snapshot(int $studentId): ?array
    {
        if (! $this->has('students')) {
            return null;
        }

        $student = $this->row('students', $studentId);
        if (! $student) {
            return null;
        }

        $columns = $this->columns('students');
        $nameColumn = $this->pick($columns, ['full_name', 'name']) ?? 'full_name';

        $class = null;
        $gradeLevel = null;
        if ($this->has('class_students') && $this->has('school_classes')) {
            $assignment = DB::table('class_students')->where('student_id', $studentId)
                ->when(in_array('status', $this->columns('class_students'), true),
                    fn ($q) => $q->where('status', 'ACTIVE'))
                ->orderByDesc('id')->first();
            if ($assignment) {
                $class = $this->row('school_classes', (int) $assignment->school_class_id);
                $gradeLevel = $class['level'] ?? $class['grade_level'] ?? null;
            }
        }

        return [
            'id' => (int) $student['id'],
            'full_name' => $student[$nameColumn] ?? null,
            'nis' => $student['nis'] ?? null,
            'nisn' => $student['nisn'] ?? null,
            'gender' => $student['gender'] ?? null,
            'campus_id' => $student['campus_id'] ?? ($student['school_id'] ?? null),
            'program_id' => $student['program_id'] ?? null,
            'class_id' => $class['id'] ?? null,
            'class_name' => $class['name'] ?? null,
            'grade_level' => $gradeLevel,
            'photo_path' => $student['photo_path'] ?? null,
            'active' => (bool) ($student['is_active'] ?? true),
            'source' => 'students',
        ];
    }

    public function snapshots(array $studentIds): array
    {
        $result = [];
        foreach (array_unique(array_filter($studentIds)) as $id) {
            $snapshot = $this->snapshot((int) $id);
            if ($snapshot) {
                $result[(int) $id] = $snapshot;
            }
        }

        return $result;
    }

    public function search(string $term, int $limit = 20): array
    {
        if (! $this->has('students') || $term === '') {
            return [];
        }

        $columns = $this->columns('students');
        $nameColumn = $this->pick($columns, ['full_name', 'name']) ?? 'full_name';
        $identifier = $this->pick($columns, ['nis', 'nisn']);

        $rows = DB::table('students')
            ->where(function ($query) use ($term, $nameColumn, $identifier): void {
                $query->where($nameColumn, 'like', '%'.$term.'%');
                if ($identifier) {
                    $query->orWhere($identifier, 'like', '%'.$term.'%');
                }
            })
            ->limit($limit)->get();

        return array_values(array_filter(array_map(
            fn ($row): ?array => $this->snapshot((int) $row->id),
            $rows->all()
        )));
    }

    public function isActive(int $studentId): bool
    {
        return (bool) ($this->snapshot($studentId)['active'] ?? false);
    }

    public function available(): bool
    {
        return $this->has('students');
    }

    public function unavailableReason(): ?string
    {
        return $this->available() ? null : 'Tabel `students` tidak tersedia pada instalasi ini.';
    }
}
