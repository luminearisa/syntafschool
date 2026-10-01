<?php

namespace App\Modules\Asset\Domain\Contracts;

/**
 * Vendor — data rekanan untuk kontrak pemeliharaan dan servis luar.
 *
 * Nama vendor TIDAK disimpan sebagai teks bebas di work order; selalu rujukan,
 * supaya rekap biaya per vendor dapat dipercaya.
 */
interface VendorProviderInterface
{
    public function vendor(int $vendorId): ?array;

    public function search(string $keyword, int $limit = 10): array;

    /** Vendor kontrak yang menaungi aset tertentu (mis. AC, lift, genset). */
    public function contractedVendors(int $assetId): array;
}
