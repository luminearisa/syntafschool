<?php

namespace App\Modules\Hrd\Infrastructure\Providers;

use App\Modules\Hrd\Application\Services\LifecycleTransactionService;
use App\Modules\Hrd\Application\Services\ReferenceService;
use App\Modules\Hrd\Domain\Contracts\HRTransactionProviderInterface;
use App\Modules\Hrd\Domain\Contracts\HrReferenceProviderInterface;
use App\Modules\Hrd\Models\HrTransaction;

/** Transaksi SDM untuk modul lain (§33, §65). */
class TransactionModuleProvider implements HRTransactionProviderInterface
{
    public function __construct(private readonly LifecycleTransactionService $transactions) {}

    public function submit(int $employeeId, string $typeCode, array $payload, ?int $actorUserId = null): array
    {
        return $this->transactions->submit($employeeId, $typeCode, $payload, $actorUserId);
    }

    public function apply(int $transactionId, ?int $actorUserId = null): array
    {
        return $this->transactions->apply($transactionId, $actorUserId);
    }

    public function findById(int $transactionId): ?array
    {
        return $this->transactions->findById($transactionId);
    }

    public function pendingInOrganization(int $organizationId): array
    {
        return $this->transactions->pendingInOrganization($organizationId);
    }

    public function hasOpenTransaction(int $employeeId): bool
    {
        return $this->transactions->hasOpenTransaction($employeeId);
    }
}
