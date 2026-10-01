<?php

namespace App\Modules\Workflow\Infrastructure\Approvers;

use App\Modules\Workflow\Application\Services\DelegationService;
use App\Modules\Workflow\Domain\Enums\ApproverSource;
use App\Modules\Workflow\Domain\Support\ResolvedApprover;
use App\Modules\Workflow\Domain\Support\WorkflowContext;
use App\Modules\Workflow\Infrastructure\Registry\WorkflowRegistrar;

/**
 * Jembatan resolver dinamis milik modul ERP (§22).
 *
 * Spesifikasi:
 * ```json
 * { "resolver": "dynamic", "resolver_alias": "boarding.musyrif_kamar",
 *   "params": { "dormitory_id": 12 } }
 * ```
 *
 * Dengan ini modul dapat menyumbang aturan approver yang sangat spesifik
 * (wali kelas, musyrif kamar, pembimbing PKL, kepala gudang) tanpa engine
 * perlu menambah satu baris pun.
 */
final class DynamicApproverResolver extends BaseApproverResolver
{
    public function __construct(
        private readonly WorkflowRegistrar $registrar,
        private readonly DelegationService $delegations,
    ) {
    }

    public function alias(): string
    {
        return 'dynamic';
    }

    public function description(): string
    {
        return 'Resolver yang didaftarkan modul ERP (resolver_alias) — titik ekstensi.';
    }

    public function requiredSpec(): array
    {
        return ['resolver_alias' => 'string'];
    }

    public function resolve(WorkflowContext $context, array $spec = []): array
    {
        $alias = (string) ($spec['resolver_alias'] ?? '');
        $resolver = $this->registrar->resolver($alias);

        if ($resolver === null || $resolver instanceof self) {
            return [];
        }

        $params = (array) ($spec['params'] ?? []);
        $resolved = [];

        foreach ($resolver->resolve($context, $params) as $approver) {
            $delegation = $approver->isDelegate
                ? null
                : $this->delegations->activeDelegationFor($approver->userId, $context);

            $resolved[] = $delegation !== null
                ? new ResolvedApprover(
                    userId: (int) $delegation->delegate_id,
                    source: ApproverSource::DELEGATION,
                    resolver: $this->alias(),
                    spec: $spec,
                    originalUserId: $approver->userId,
                    delegationId: (int) $delegation->id,
                    isDelegate: true,
                )
                : new ResolvedApprover(
                    userId: $approver->userId,
                    source: ApproverSource::DYNAMIC,
                    resolver: $this->alias(),
                    spec: $spec + ['resolved_by' => $alias],
                    note: $approver->note,
                );
        }

        return $resolved;
    }
}
