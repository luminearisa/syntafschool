<?php

namespace App\Modules\Workflow\Infrastructure\Approvers;

use App\Modules\Workflow\Application\Services\DelegationService;
use App\Modules\Workflow\Domain\Contracts\ApproverResolverInterface;
use App\Modules\Workflow\Domain\Enums\ApproverSource;
use App\Modules\Workflow\Domain\Support\ResolvedApprover;
use App\Modules\Workflow\Domain\Support\WorkflowContext;

/**
 * Resolver generik: mengubah daftar id pengguna menjadi approver (§23).
 *
 * Bila seorang approver sedang mendelegasikan kewenangannya pada rentang waktu
 * ini, penugasan dialihkan ke penerima delegasi — dan identitas pemberi
 * delegasi tetap tercatat (`originalUserId`, `delegationId`).
 */
final class UserApproverResolver extends BaseApproverResolver
{
    public function __construct(
        private readonly DelegationService $delegations,
    ) {
    }

    public function alias(): string
    {
        return 'user';
    }

    public function description(): string
    {
        return 'Pengguna tertentu (user_id), dengan dukungan delegasi aktif.';
    }

    public function requiredSpec(): array
    {
        return ['user_id' => 'array|int'];
    }

    public function resolve(WorkflowContext $context, array $spec = []): array
    {
        $ids = $this->listValue($spec, 'user_id');
        $resolved = [];

        foreach ($ids as $id) {
            $userId = (int) $id;

            if ($userId <= 0) {
                continue;
            }

            $delegation = $this->delegations->activeDelegationFor($userId, $context);

            if ($delegation !== null) {
                $resolved[] = new ResolvedApprover(
                    userId: (int) $delegation->delegate_id,
                    source: ApproverSource::DELEGATION,
                    resolver: $this->alias(),
                    spec: $spec,
                    originalUserId: $userId,
                    delegationId: (int) $delegation->id,
                    isDelegate: true,
                    note: 'Delegasi aktif '.$delegation->start_at?->toDateString().' → '.$delegation->end_at?->toDateString(),
                );

                continue;
            }

            $resolved[] = new ResolvedApprover(
                userId: $userId,
                source: ApproverSource::USER,
                resolver: $this->alias(),
                spec: $spec,
            );
        }

        return $resolved;
    }
}
