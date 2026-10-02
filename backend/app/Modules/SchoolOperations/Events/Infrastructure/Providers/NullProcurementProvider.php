<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Pengadaan.
 *
 * Modul Pengadaan belum terpasang; kebutuhan pengadaan dicatat sebagai permintaan (§56).
 */
class NullProcurementProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Modul Pengadaan belum terpasang; kebutuhan pengadaan dicatat sebagai permintaan (§56).';

    protected const TARGET_CONTRACT = null;

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function request(array $payload): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function status(int $requestId): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }
}
