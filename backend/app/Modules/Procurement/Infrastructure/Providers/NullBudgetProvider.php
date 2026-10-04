<?php

namespace App\Modules\Procurement\Infrastructure\Providers;

use App\Modules\Procurement\Domain\Contracts\BudgetProviderInterface;
use App\Modules\Procurement\Domain\Support\IntegrationResult;

/**
 * Implementasi bawaan bila modul Budget belum terpasang.
 *
 * `checkAvailability()` mengembalikan `sufficient = true` dengan `source = NONE`
 * agar pengadaan tidak diblokir oleh data anggaran yang tidak diketahui; aturan
 * ini dapat diubah lewat `config('procurement')`/Setting. Komitmen tetap dicatat
 * sebagai PENDING agar tidak hilang.
 */
class NullBudgetProvider implements BudgetProviderInterface
{
    public const CODE = 'PROVIDER_NOT_INSTALLED';

    public function checkAvailability(array $payload): array
    {
        return [
            'budget' => 0.0,
            'committed' => 0.0,
            'actual' => 0.0,
            'available' => 0.0,
            'sufficient' => true,
            'source' => 'NONE',
        ];
    }

    public function commit(array $payload): IntegrationResult
    {
        return IntegrationResult::failure(
            'Modul Anggaran belum terpasang; komitmen dicatat sebagai PENDING.',
            self::CODE,
        );
    }

    public function release(array $payload): IntegrationResult
    {
        return IntegrationResult::failure(
            'Modul Anggaran belum terpasang; pelepasan komitmen dicatat sebagai PENDING.',
            self::CODE,
        );
    }
}
