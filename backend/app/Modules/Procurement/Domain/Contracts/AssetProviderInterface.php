<?php

namespace App\Modules\Procurement\Domain\Contracts;

use App\Modules\Procurement\Domain\Support\IntegrationResult;

/**
 * Asset — sumber kebenaran aset tetap.
 *
 * Procurement hanya mengirim informasi perolehan; modul Asset yang menangani
 * nomor aset, kapitalisasi, lokasi, kustodian, penyusutan, dan penghapusan.
 */
interface AssetProviderInterface
{
    /**
     * Daftarkan perolehan aset dari goods receipt (item ber-flag aset).
     *
     * @param  array<string, mixed>  $payload
     */
    public function registerAcquisition(array $payload): IntegrationResult;
}
