<?php

namespace App\Modules\Workflow\Infrastructure\Approvers;

use App\Modules\Workflow\Application\Services\DelegationService;
use App\Modules\Workflow\Domain\Contracts\OrganizationalDirectoryInterface;
use App\Modules\Workflow\Domain\Enums\ApproverSource;
use App\Modules\Workflow\Domain\Support\ResolvedApprover;
use App\Modules\Workflow\Domain\Support\WorkflowContext;

/**
 * Resolver kepala unit/departemen (§26).
 *
 * Unit diambil dari konteks (`department`) atau dari direktori berdasarkan
 * requester — urutan itu penting: request lintas unit (mis. pengadaan untuk
 * unit lain) harus disetujui kepala unit **tujuan**, bukan unit pengaju.
 */
final class DepartmentApproverResolver extends BaseApproverResolver
{
    public function __construct(
        private readonly OrganizationalDirectoryInterface $directory,
        private readonly DelegationService $delegations,
    ) {
    }

    public function alias(): string
    {
        return 'department';
    }

    public function description(): string
    {
        return 'Kepala unit/departemen pemilik request (dari konteks `department` bila ada).';
    }

    public function requiredSpec(): array
    {
        return [];
    }

    public function resolve(WorkflowContext $context, array $spec = []): array
    {
        $field = (string) ($spec['field'] ?? 'department');
        $department = $context->get($field)
            ?? $this->directory->departmentOf($context->requesterId());

        if ($department === null || $department === '') {
            if (isset($spec['fallback_user_id'])) {
                return [new ResolvedApprover(
                    userId: (int) $spec['fallback_user_id'],
                    source: ApproverSource::FALLBACK,
                    resolver: $this->alias(),
                    spec: $spec,
                    note: 'Unit tidak diketahui; memakai approver cadangan.',
                )];
            }

            return [];
        }

        $resolved = [];

        foreach ($this->directory->headsOfDepartment((string) $department) as $userId) {
            $delegation = $this->delegations->activeDelegationFor((int) $userId, $context);

            $resolved[] = $delegation !== null
                ? new ResolvedApprover(
                    userId: (int) $delegation->delegate_id,
                    source: ApproverSource::DELEGATION,
                    resolver: $this->alias(),
                    spec: $spec + ['department' => (string) $department],
                    originalUserId: (int) $userId,
                    delegationId: (int) $delegation->id,
                    isDelegate: true,
                )
                : new ResolvedApprover(
                    userId: (int) $userId,
                    source: ApproverSource::DEPARTMENT,
                    resolver: $this->alias(),
                    spec: $spec + ['department' => (string) $department],
                );
        }

        return $resolved;
    }
}
