<?php

namespace App\Modules\Workflow\Infrastructure\Approvers;

use App\Modules\Workflow\Application\Services\DelegationService;
use App\Modules\Workflow\Domain\Contracts\OrganizationalDirectoryInterface;
use App\Modules\Workflow\Domain\Enums\ApproverSource;
use App\Modules\Workflow\Domain\Support\ResolvedApprover;
use App\Modules\Workflow\Domain\Support\WorkflowContext;

/**
 * Resolver "atasan langsung" dari requester (§25).
 *
 * Sumber kebenaran hierarki adalah `OrganizationalDirectoryInterface`, bukan
 * engine. Bila atasan langsung tidak ada, resolver dapat memakai **cadangan**
 * (`fallback_user_id` pada spesifikasi) atau melanjutkan ke atasan dari atasan
 * (`levels: 2` untuk "satu tingkat di atas atasan").
 */
final class ManagerApproverResolver extends BaseApproverResolver
{
    public function __construct(
        private readonly OrganizationalDirectoryInterface $directory,
        private readonly DelegationService $delegations,
    ) {
    }

    public function alias(): string
    {
        return 'manager';
    }

    public function description(): string
    {
        return 'Atasan langsung requester (levels: 1 = atasan, 2 = atasannya lagi).';
    }

    public function requiredSpec(): array
    {
        return [];
    }

    public function resolve(WorkflowContext $context, array $spec = []): array
    {
        $levels = max(1, (int) ($spec['levels'] ?? 1));
        $useBackup = (bool) ($spec['use_backup'] ?? true);
        $fallback = $spec['fallback_user_id'] ?? null;

        $current = $context->requesterId();
        $resolved = [];

        for ($level = 0; $level < $levels; $level++) {
            if ($current === null) {
                break;
            }

            $managerId = $this->directory->managerOf($current);

            if ($managerId === null && $level === 0 && $useBackup) {
                $managerId = $this->directory->backupManagerOf($current);
            }

            if ($managerId === null) {
                break;
            }

            $current = $managerId;
        }

        if ($current === null && $fallback !== null) {
            $current = (int) $fallback;
        }

        if ($current === null || $current === $context->requesterId()) {
            return [];
        }

        $delegation = $this->delegations->activeDelegationFor($current, $context);

        if ($delegation !== null) {
            return [new ResolvedApprover(
                userId: (int) $delegation->delegate_id,
                source: ApproverSource::DELEGATION,
                resolver: $this->alias(),
                spec: $spec,
                originalUserId: $current,
                delegationId: (int) $delegation->id,
                isDelegate: true,
            )];
        }

        return [new ResolvedApprover(
            userId: $current,
            source: ApproverSource::MANAGER,
            resolver: $this->alias(),
            spec: $spec,
        )];
    }
}
