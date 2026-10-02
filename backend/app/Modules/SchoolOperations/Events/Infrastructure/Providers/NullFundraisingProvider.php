<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Penggalangan Dana.
 *
 * Modul Penggalangan Dana belum terpasang; komitmen sponsor dicatat di modul Event tanpa mesin akuntansi kedua (§54).
 */
class NullFundraisingProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Modul Penggalangan Dana belum terpasang; komitmen sponsor dicatat di modul Event tanpa mesin akuntansi kedua (§54).';

    protected const TARGET_CONTRACT = null;

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function createCampaign(array $payload): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function recordCommitment(array $payload): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }
}
