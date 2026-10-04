<?php

namespace App\Modules\Procurement\Domain\Contracts;

use App\Modules\Procurement\Domain\Support\IntegrationResult;

/**
 * Inventory — sumber kebenaran stok.
 *
 * Procurement **tidak** membuat kartu stok sendiri. Saat barang diterima dan
 * lolos inspeksi, ia meminta Inventory mencatat penerimaan stok.
 */
interface InventoryProviderInterface
{
    /**
     * Catat penerimaan stok dari goods receipt.
     *
     * @param  array<string, mixed>  $payload
     */
    public function receive(array $payload): IntegrationResult;

    /**
     * Sisa stok yang tersedia untuk suatu item (dipakai validasi PR:
     * "minta 100, tersedia 60, saran pengadaan 40").
     *
     * @return array<string, mixed>|null
     */
    public function stockOnHand(string $itemReference, ?int $warehouseId = null): ?array;
}
