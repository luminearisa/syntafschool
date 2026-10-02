<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Keuangan.
 *
 * Modul Keuangan belum terpasang; anggaran dan realisasi dicatat di modul Event tanpa membentuk ledger kedua (§51, §52).
 */
class NullFinanceProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Modul Keuangan belum terpasang; anggaran dan realisasi dicatat di modul Event tanpa membentuk ledger kedua (§51, §52).';

    protected const TARGET_CONTRACT = null;

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function createBudgetRequest(int $eventId, array $lines, array $meta = []): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function postCost(int $eventId, array $payload): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function reconcile(int $eventId, array $payload = []): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }
}
