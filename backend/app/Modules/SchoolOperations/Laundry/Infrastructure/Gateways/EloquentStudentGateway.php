<?php

namespace App\Modules\SchoolOperations\Laundry\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Laundry\Domain\Contracts\StudentProviderInterface;
use Illuminate\Support\Facades\DB;

/**
 * Snapshot santri dari master Academic/Student (Rule: modul lain sumber
 * kebenaran). Membaca langsung tabel inti dengan deteksi kolom agar tetap
 * jalan pada instalasi yang skemanya lebih ramping.
 */
class EloquentStudentGateway implements StudentProviderInterface
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
        $level = null;
        if ($this->has('class_students') && $this->has('school_classes')) {
            $activeColumn = in_array('status', $this->columns('class_students'), true) ? 'status' : null;
            $assignment = DB::table('class_students')->where('student_id', $studentId)
                ->when($activeColumn, fn ($q) => $q->where($activeColumn, 'ACTIVE'))
                ->orderByDesc('id')
                ->first();
            if ($assignment) {
                $class = $this->row('school_classes', (int) $assignment->school_class_id);
                if ($class && ! empty($class['education_level_id']) && $this->has('education_levels')) {
                    $level = $this->row('education_levels', (int) $class['education_level_id']);
                }
            }
        }

        return [
            'id' => (int) $student['id'],
            'full_name' => $student[$nameColumn] ?? null,
            'nis' => $student['nis'] ?? null,
            'nisn' => $student['nisn'] ?? null,
            'school_id' => isset($student['school_id']) ? (int) $student['school_id'] : null,
            'education_level_id' => isset($level['id']) ? (int) $level['id'] : null,
            'level_name' => $level['name'] ?? null,
            'class_id' => isset($class['id']) ? (int) $class['id'] : null,
            'class_name' => $class['name'] ?? null,
            'photo_path' => $student['photo_path'] ?? null,
            'active' => (bool) ($student['is_active'] ?? true),
            'source' => 'students',
        ];
    }

    public function snapshots(array $studentIds): array
    {
        $result = [];
        foreach (array_unique(array_filter(array_map('intval', $studentIds))) as $id) {
            $snapshot = $this->snapshot($id);
            if ($snapshot) {
                $result[$id] = $snapshot;
            }
        }

        return $result;
    }

    public function search(string $term, int $limit = 20): array
    {
        if (! $this->has('students') || trim($term) === '') {
            return [];
        }

        $columns = $this->columns('students');
        $nameColumn = $this->pick($columns, ['full_name', 'name']) ?? 'full_name';
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        $rows = DB::table('students')
            ->where(function ($q) use ($like, $nameColumn, $columns): void {
                $q->where($nameColumn, 'like', $like);
                foreach (['nis', 'nisn'] as $candidate) {
                    if (in_array($candidate, $columns, true)) {
                        $q->orWhere($candidate, 'like', $like);
                    }
                }
            })
            ->orderBy($nameColumn)
            ->limit(max(1, min($limit, 50)))
            ->get();

        return array_values(array_map(fn ($row): array => [
            'id' => (int) $row->id,
            'full_name' => $row->{$nameColumn} ?? null,
            'nis' => $row->nis ?? null,
            'active' => (bool) ($row->is_active ?? true),
        ], $rows->all()));
    }

    public function isActive(int $studentId): bool
    {
        $snapshot = $this->snapshot($studentId);

        return $snapshot !== null && (bool) $snapshot['active'];
    }

    public function available(): bool
    {
        return $this->has('students');
    }
}
