<?php

namespace App\Modules\Workflow\Infrastructure\Directory;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Workflow\Domain\Contracts\OrganizationalDirectoryInterface;
use App\Modules\Workflow\Domain\Enums\OrgScopeType;
use App\Modules\Workflow\Models\WorkflowOrgAssignment;
use App\Modules\Workflow\Models\WorkflowOrgReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Direktori organisasi bawaan engine.
 *
 * Prioritas sumber data — dari yang paling terintegrasi ke yang paling netral:
 *
 * 1. **ERP**: `employees.position` (lewat relasi `users.userable`) untuk jabatan,
 *    dan `finance_cost_centers.manager_id` untuk cost center — infrastruktur
 *    yang sudah ada tidak diduplikasi.
 * 2. **Peta engine**: `workflow_org_reports` (atasan) dan
 *    `workflow_org_assignments` (unit/jabatan/kampus/tim) yang dikelola admin
 *    pada layar *Direktori Workflow*, untuk hal yang belum dimiliki ERP
 *    (mis. "siapa kepala unit Sarana").
 *
 * Sekolah yang sudah memiliki bagan organisasi sendiri dapat mengikat kontrak
 * `OrganizationalDirectoryInterface` ke implementasi lain lewat satu baris di
 * `WorkflowServiceProvider`.
 */
final class DatabaseOrganizationalDirectory implements OrganizationalDirectoryInterface
{
    public function managerOf(?int $userId): ?int
    {
        if ($userId === null) {
            return null;
        }

        $report = $this->reportFor($userId);

        if ($report === null) {
            return null;
        }

        return (int) $report->manager_user_id;
    }

    public function backupManagerOf(?int $userId): ?int
    {
        if ($userId === null) {
            return null;
        }

        $report = $this->reportFor($userId);
        $backup = $report?->backup_user_id;

        return $backup === null ? null : (int) $backup;
    }

    public function departmentOf(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $assignment = WorkflowOrgAssignment::query()
            ->where('user_id', $userId)
            ->where('scope_type', OrgScopeType::DEPARTMENT->value)
            ->activeAt()
            ->orderByDesc('is_primary')
            ->orderBy('priority')
            ->first();

        return $assignment?->scope_key;
    }

    public function headsOfDepartment(string $department): array
    {
        $ids = WorkflowOrgAssignment::query()
            ->where('scope_type', OrgScopeType::DEPARTMENT->value)
            ->whereRaw('LOWER(scope_key) = ?', [mb_strtolower($department)])
            ->activeAt()
            ->orderByDesc('is_primary')
            ->orderBy('priority')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $this->onlyActiveUsers($ids);
    }

    public function holdersOfPosition(string $position): array
    {
        $assignmentIds = WorkflowOrgAssignment::query()
            ->where('scope_type', OrgScopeType::POSITION->value)
            ->whereRaw('LOWER(scope_key) = ?', [mb_strtolower($position)])
            ->activeAt()
            ->orderByDesc('is_primary')
            ->orderBy('priority')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $employeeIds = Employee::query()
            ->whereRaw('LOWER(position) = ?', [mb_strtolower($position)])
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $employeeUserIds = $employeeIds === []
            ? []
            : User::query()
                ->where('userable_type', (new Employee())->getMorphClass())
                ->whereIn('userable_id', $employeeIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

        return $this->onlyActiveUsers(array_merge($assignmentIds, $employeeUserIds));
    }

    public function usersInScope(string $scopeType, string|int $scopeKey): array
    {
        $ids = WorkflowOrgAssignment::query()
            ->where('scope_type', $scopeType)
            ->where('scope_key', (string) $scopeKey)
            ->activeAt()
            ->orderByDesc('is_primary')
            ->orderBy('priority')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $this->onlyActiveUsers($ids);
    }

    public function primaryHolder(string $scopeType, string|int $scopeKey): ?int
    {
        $assignment = WorkflowOrgAssignment::query()
            ->where('scope_type', $scopeType)
            ->where('scope_key', (string) $scopeKey)
            ->activeAt()
            ->orderByDesc('is_primary')
            ->orderBy('priority')
            ->first();

        if ($assignment !== null) {
            return (int) $assignment->user_id;
        }

        // Cost center: pakai manajer yang sudah tercatat di modul Finance.
        if ($scopeType === OrgScopeType::COST_CENTER->value && Schema::hasTable('finance_cost_centers')) {
            $managerId = DB::table('finance_cost_centers')
                ->where('id', (int) $scopeKey)
                ->value('manager_id');

            return $managerId === null ? null : (int) $managerId;
        }

        return null;
    }

    protected function reportFor(int $userId): ?WorkflowOrgReport
    {
        return WorkflowOrgReport::query()
            ->where('user_id', $userId)
            ->get()
            ->first(fn (WorkflowOrgReport $report) => $report->isEffectiveOn());
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    protected function onlyActiveUsers(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
