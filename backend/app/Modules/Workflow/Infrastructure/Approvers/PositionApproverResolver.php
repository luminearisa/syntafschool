<?php

namespace App\Modules\Workflow\Infrastructure\Approvers;

use App\Modules\Workflow\Application\Services\DelegationService;
use App\Modules\Workflow\Domain\Contracts\OrganizationalDirectoryInterface;
use App\Modules\Workflow\Domain\Enums\ApproverSource;
use App\Modules\Workflow\Domain\Support\ResolvedApprover;
use App\Modules\Workflow\Domain\Support\WorkflowContext;

/**
 * Resolver berbasis jabatan — nama jabatan bebas, mis. "Kepala Sekolah",
 * "Wakil Kurikulum", "Musyrif Kepala" (§25 varian jabatan).
 */
final class PositionApproverResolver extends BaseApproverResolver
{
    public function __construct(
        private readonly OrganizationalDirectoryInterface $directory,
        private readonly DelegationService $delegations,
    ) {
    }

    public function alias(): string
    {
        return 'position';
    }

    public function description(): string
    {
        return 'Pemegang jabatan tertentu (mis. Kepala Sekolah, Bendahara).';
    }

    public function requiredSpec(): array
    {
        return ['position' => 'string|array'];
    }

    public function resolve(WorkflowContext $context, array $spec = []): array
    {
        $resolved = [];

        foreach ($this->listValue($spec, 'position') as $position) {
            foreach ($this->directory->holdersOfPosition((string) $position) as $userId) {
                $delegation = $this->delegations->activeDelegationFor((int) $userId, $context);

                $resolved[] = $delegation !== null
                    ? new ResolvedApprover(
                        userId: (int) $delegation->delegate_id,
                        source: ApproverSource::DELEGATION,
                        resolver: $this->alias(),
                        spec: $spec + ['position' => (string) $position],
                        originalUserId: (int) $userId,
                        delegationId: (int) $delegation->id,
                        isDelegate: true,
                    )
                    : new ResolvedApprover(
                        userId: (int) $userId,
                        source: ApproverSource::POSITION,
                        resolver: $this->alias(),
                        spec: $spec + ['position' => (string) $position],
                    );
            }
        }

        return $resolved;
    }
}
