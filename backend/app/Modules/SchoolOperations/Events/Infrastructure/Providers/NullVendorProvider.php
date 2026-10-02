<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Vendor.
 *
 * Modul Vendor belum terpasang; data vendor dicatat sebagai referensi pada dokumen event (§55).
 */
class NullVendorProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Modul Vendor belum terpasang; data vendor dicatat sebagai referensi pada dokumen event (§55).';

    protected const TARGET_CONTRACT = null;

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function find(int $id): ?array
    {
        return null;
    }

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function search(array $filters = []): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }
}
