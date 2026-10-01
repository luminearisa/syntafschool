<?php

namespace App\Modules\Asset\Domain\Contracts;

/**
 * Persediaan — sumber kebenaran stok (habis pakai dan suku cadang).
 *
 * Suku cadang TIDAK disimpan dua kali di modul Asset. Work order meminta
 * pengeluaran barang, dan persediaan yang memutuskan tersedia atau tidak.
 */
interface InventoryProviderInterface
{
    public function item(string $code): ?array;

    /** Keluarkan barang dari gudang untuk sebuah work order; menolak bila stok kurang. */
    public function issue(array $payload): array;

    /** Terima kembali barang yang tidak terpakai (retur ke gudang). */
    public function receive(array $payload): array;

    /** Barang habis pakai yang dibeli lewat penerimaan barang, tanpa menjadi aset. */
    public function recordConsumable(array $payload): array;
}
