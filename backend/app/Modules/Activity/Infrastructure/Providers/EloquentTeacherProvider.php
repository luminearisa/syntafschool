<?php

namespace App\Modules\Activity\Infrastructure\Providers;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Activity\Domain\Contracts\TeacherProviderInterface;
use Illuminate\Support\Facades\Schema;

/**
 * Pembina dan pelatih dari master pegawai.
 */
class EloquentTeacherProvider implements TeacherProviderInterface
{
    public function profile(int $employeeId): ?array
    {
        $employee = Employee::query()->find($employeeId);

        if ($employee === null) {
            return null;
        }

        return [
            'id' => (int) $employee->id,
            'name' => (string) $employee->full_name,
            'nip' => $employee->nip,
            'position' => $employee->position,
            'email' => $employee->email,
            'phone' => $employee->phone,
            'is_teacher' => (bool) $employee->is_teacher,
        ];
    }

    public function userIdsForEmployee(int $employeeId): array
    {
        $employee = Employee::query()->find($employeeId);

        if ($employee === null) {
            return [];
        }

        if (Schema::hasColumn('employees', 'user_id') && $employee->user_id !== null) {
            return [(int) $employee->user_id];
        }

        if ($employee->email === null) {
            return [];
        }

        $userId = User::query()->where('email', $employee->email)->value('id');

        return $userId === null ? [] : [(int) $userId];
    }

    public function search(string $term, int $limit = 20): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $like = '%'.$term.'%';

        return Employee::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('full_name', 'like', $like)->orWhere('nip', 'like', $like))
            ->limit($limit)
            ->get()
            ->map(fn (Employee $employee) => [
                'id' => (int) $employee->id,
                'name' => (string) $employee->full_name,
                'nip' => $employee->nip,
                'position' => $employee->position,
            ])
            ->all();
    }
}
