<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Inventaris.
 *
 * Modul Inventaris belum terpasang; permintaan barang dicatat di modul Event dan dapat diteruskan setelah modul tersedia (§47).
 */
class NullInventoryProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Modul Inventaris belum terpasang; permintaan barang dicatat di modul Event dan dapat diteruskan setelah modul tersedia (§47).';

    protected const TARGET_CONTRACT = null;

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function find(int $id): ?array
    {
        return null;
    }

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function stock(int $itemId): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function request(array $payload): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function issue(int $requestId, array $payload = []): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }
}
