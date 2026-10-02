<?php

namespace App\Modules\Hrd\Infrastructure\Providers;

use App\Modules\Hrd\Application\Services\HrdWorkflowService;
use App\Modules\Hrd\Domain\Contracts\HRWorkflowProviderInterface;

/**
 * Titik masuk Workflow Engine ke data SDM (§2, §55, §66).
 *
 * Engine menentukan approver lewat kontrak ini; HRD memulai persetujuan lewat
 * kontrak yang sama. Jadi tidak ada mesin persetujuan kedua — dan tidak ada
 * daftar "siapa atasan siapa" yang hidup di dua tempat.
 */
class WorkflowModuleProvider implements HRWorkflowProviderInterface
{
    public function __construct(private readonly HrdWorkflowService $workflow) {}

    public function managerUserIdOf(int $employeeId): ?int
    {
        $employee = \App\Modules\Hrd\Models\HrEmployee::query()->find($employeeId);

        if ($employee === null) {
            return null;
        }

        $managerId = $employee->currentManagerId();

        return $managerId === null
            ? null
            : \App\Modules\Hrd\Models\HrEmployee::query()->whereKey($managerId)->value('user_id');
    }

    public function positionHolderUserId(int $positionId, ?string $onDate = null): ?int
    {
        $assignment = \App\Modules\Hrd\Models\HrEmployeeAssignment::query()
            ->with('employee:id,user_id')
            ->where('position_id', $positionId)
            ->whereIn('status', ['active'])
            ->effectiveOn($onDate ?? now()->toDateString())
            ->orderByDesc('is_primary')
            ->first();

        return $assignment?->employee?->user_id === null ? null : (int) $assignment->employee->user_id;
    }

    public function organizationHeadUserId(int $organizationId): ?int
    {
        $head = app(\App\Modules\Hrd\Domain\Contracts\OrganizationProviderInterface::class)->headOf($organizationId);

        return isset($head['user_id']) && $head['user_id'] !== null ? (int) $head['user_id'] : null;
    }

    public function startApproval(string $definitionCode, string $subjectType, int $subjectId, array $payload = [], ?int $actorUserId = null): array
    {
        return $this->workflow->startApproval(
            $definitionCode,
            $subjectType,
            $subjectId,
            $payload,
            $actorUserId === null ? null : \App\Models\User::query()->find($actorUserId),
        );
    }

    public function approvalStatus(string $subjectType, int $subjectId): ?string
    {
        return $this->workflow->approvalStatus($subjectType, $subjectId);
    }
}
