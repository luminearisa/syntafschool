<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Klinik.
 *
 * Modul Klinik belum terpasang; rencana medis dicatat sebagai rencana tanpa penugasan tenaga medis nyata (§59).
 */
class NullClinicProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Modul Klinik belum terpasang; rencana medis dicatat sebagai rencana tanpa penugasan tenaga medis nyata (§59).';

    protected const TARGET_CONTRACT = null;

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function staff(int $campusId): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function createSupport(array $payload): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }
}
