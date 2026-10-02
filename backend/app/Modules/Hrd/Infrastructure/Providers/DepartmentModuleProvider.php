<?php

namespace App\Modules\Hrd\Infrastructure\Providers;

use App\Modules\Hrd\Domain\Contracts\DepartmentProviderInterface;
use App\Modules\Hrd\Models\HrDepartment;
use App\Modules\Hrd\Models\HrEmployee;

/**
 * Departemen untuk modul lain (§2, §65).
 *
 * Dipisahkan dari kontrak organisasi karena keduanya memiliki metode pencarian
 * yang sama namanya (`findById`/`findByCode`) tetapi menunjuk tabel berbeda —
 * menggabungkannya dalam satu kelas akan membuat "id ini unit atau departemen?"
 * menjadi pertanyaan yang harus dijawab pemanggil, dan itu sumber kesalahan.
 */
class DepartmentModuleProvider implements DepartmentProviderInterface
{
    public function findById(int $departmentId): ?array
    {
        return HrDepartment::query()->find($departmentId)?->toArray();
    }

    public function findByCode(string $code): ?array
    {
        return HrDepartment::query()->where('code', $code)->first()?->toArray();
    }

    public function inOrganization(int $organizationId, bool $activeOnly = true): array
    {
        return HrDepartment::query()
            ->where('organization_id', $organizationId)
            ->when($activeOnly, fn ($q) => $q->active())
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'organization_id', 'parent_id', 'head_employee_id', 'status'])
            ->toArray();
    }

    public function headOfDepartment(int $departmentId): ?array
    {
        $department = HrDepartment::query()->with('head.person:id,full_name')->find($departmentId);

        if ($department?->head !== null) {
            return [
                'employee_id' => (int) $department->head->getKey(),
                'name' => $department->head->displayName(),
                'user_id' => $department->head->user_id,
            ];
        }

        // Pimpinan posisi di departemen tersebut, bila `head_employee_id` kosong.
        $head = HrEmployee::query()
            ->with('person:id,full_name')
            ->where('department_id', $departmentId)
            ->active()
            ->whereHas('position', fn ($q) => $q->where('title', 'like', '%kepala%'))
            ->first();

        return $head === null ? null : [
            'employee_id' => (int) $head->getKey(),
            'name' => $head->displayName(),
            'user_id' => $head->user_id,
        ];
    }

    /** @return list<int> */
    public function withChildren(int $departmentId): array
    {
        $ids = [$departmentId];
        $frontier = [$departmentId];

        while ($frontier !== []) {
            $frontier = HrDepartment::query()->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $ids = array_merge($ids, $frontier);
        }

        return array_values(array_unique($ids));
    }
}
