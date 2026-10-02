<?php

namespace App\Modules\Hrd\Infrastructure\Providers;

use App\Modules\Hrd\Application\Services\AssignmentService;
use App\Modules\Hrd\Application\Services\EmployeeService;
use App\Modules\Hrd\Domain\Contracts\EmployeeProviderInterface;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrOrganization;

/** Data pegawai untuk modul lain (§2, §65) — hanya lewat kontrak ini. */
class EmployeeModuleProvider implements EmployeeProviderInterface
{
    public function __construct(private readonly EmployeeService $employees, private readonly AssignmentService $assignments) {}

    public function findByNumber(string $employeeNumber): ?array
    {
        return HrEmployee::query()->where('employee_number', $employeeNumber)->first() === null
            ? null
            : $this->summary((int) HrEmployee::query()->where('employee_number', $employeeNumber)->value('id'));
    }

    public function findById(int $employeeId): ?array
    {
        return $this->summary($employeeId);
    }

    public function findByUserId(int $userId): ?array
    {
        $id = HrEmployee::query()->where('user_id', $userId)->value('id');

        return $id === null ? null : $this->summary((int) $id);
    }

    public function activeInOrganization(int $organizationId, bool $includeDescendants = false): array
    {
        $organization = HrOrganization::query()->find($organizationId);
        $ids = [$organizationId];

        if ($includeDescendants && $organization !== null) {
            $ids = HrOrganization::query()->where('path', 'like', ($organization->path ?? '/'.$organizationId.'/').'%')->pluck('id')->all();
        }

        return HrEmployee::query()
            ->active()
            ->whereIn('organization_id', $ids)
            ->get()
            ->map(fn (HrEmployee $employee) => $this->summary((int) $employee->getKey()))
            ->filter()
            ->values()
            ->all();
    }

    public function directReportsOf(int $employeeId): array
    {
        return HrEmployee::query()
            ->active()
            ->where('manager_employee_id', $employeeId)
            ->get()
            ->map(fn (HrEmployee $employee) => $this->summary((int) $employee->getKey()))
            ->filter()
            ->values()
            ->all();
    }

    public function isActive(int $employeeId, ?string $onDate = null): bool
    {
        $employee = HrEmployee::query()->with(['contracts', 'assignments'])->find($employeeId);

        if ($employee === null || ! $employee->isActive()) {
            return false;
        }

        $date = $onDate ?? now()->toDateString();

        return $employee->assignments->where('status', 'active')->isEmpty()
            || $employee->assignments->contains(fn ($assignment) => $assignment->start_date?->toDateString() <= $date
                && ($assignment->end_date === null || $assignment->end_date->toDateString() >= $date));
    }

    public function summary(int $employeeId): ?array
    {
        $employee = HrEmployee::query()->with(['person:id,full_name,work_email,photo_path'])->find($employeeId);

        if ($employee === null) {
            return null;
        }

        return $this->employees->summaryCard($employee);
    }
}
