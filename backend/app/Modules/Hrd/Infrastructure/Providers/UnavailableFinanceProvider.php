<?php

namespace App\Modules\Hrd\Infrastructure\Providers;

use App\Modules\Hrd\Domain\Contracts\FinanceProviderInterface;
use Illuminate\Support\Facades\Schema;

/**
 * Keuangan belum terpasang (§66): clearance keuangan tidak dapat diminta, tetapi
 * proses keluar tetap berjalan dan permintaannya menunggu di outbox.
 */
class UnavailableFinanceProvider implements FinanceProviderInterface
{
    public function registerEmployee(int $employeeId, array $payload): bool
    {
        return false;
    }

    public function outstandingFor(int $employeeId): array
    {
        return [];
    }

    public function requestClearance(int $employeeId, array $payload): ?array
    {
        return null;
    }

    public function hasOutstandingObligations(int $employeeId): bool
    {
        return false;
    }

    public function isAvailable(): bool
    {
        return Schema::hasTable('finance_bills');
    }
}
