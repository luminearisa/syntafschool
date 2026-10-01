<?php

namespace App\Modules\Workflow\Infrastructure\Approvers;

use App\Modules\Workflow\Application\Services\DelegationService;
use App\Modules\Workflow\Domain\Contracts\OrganizationalDirectoryInterface;
use App\Modules\Workflow\Domain\Enums\ApproverSource;
use App\Modules\Workflow\Domain\Enums\OrgScopeType;
use App\Modules\Workflow\Domain\Support\ResolvedApprover;
use App\Modules\Workflow\Domain\Support\WorkflowContext;

/**
 * Resolver manajer cost center (§27).
 *
 * Cost center dibaca dari konteks (`cost_center_id` secara default) — nilai itu
 * disumbangkan modul Finance lewat context provider, bukan ditebak engine.
 */
final class CostCenterApproverResolver extends BaseApproverResolver
{
    public function __construct(
        private readonly OrganizationalDirectoryInterface $directory,
        private readonly DelegationService $delegations,
    ) {
    }

    public function alias(): string
    {
        return 'cost_center';
    }

    public function description(): string
    {
        return 'Manajer cost center pemilik anggaran (konteks `cost_center_id`).';
    }

    public function requiredSpec(): array
    {
        return [];
    }

    public function resolve(WorkflowContext $context, array $spec = []): array
    {
        $field = (string) ($spec['field'] ?? 'cost_center_id');
        $costCenter = $context->get($field);

        if ($costCenter === null || $costCenter === '') {
            return isset($spec['fallback_user_id'])
                ? [new ResolvedApprover(
                    userId: (int) $spec['fallback_user_id'],
                    source: ApproverSource::FALLBACK,
                    resolver: $this->alias(),
                    spec: $spec,
                    note: 'Cost center tidak diketahui.',
                )]
                : [];
        }

        $primary = $this->directory->primaryHolder(OrgScopeType::COST_CENTER->value, (string) $costCenter);

        if ($primary === null) {
            return [];
        }

        $delegation = $this->delegations->activeDelegationFor($primary, $context);

        if ($delegation !== null) {
            return [new ResolvedApprover(
                userId: (int) $delegation->delegate_id,
                source: ApproverSource::DELEGATION,
                resolver: $this->alias(),
                spec: $spec + ['cost_center_id' => $costCenter],
                originalUserId: $primary,
                delegationId: (int) $delegation->id,
                isDelegate: true,
            )];
        }

        return [new ResolvedApprover(
            userId: $primary,
            source: ApproverSource::COST_CENTER,
            resolver: $this->alias(),
            spec: $spec + ['cost_center_id' => $costCenter],
        )];
    }
}
