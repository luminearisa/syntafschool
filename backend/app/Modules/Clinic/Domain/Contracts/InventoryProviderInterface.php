<?php

namespace App\Modules\Clinic\Domain\Contracts;

use App\Modules\Clinic\Domain\Enums\InventoryStockResult;

/**
 * Satu-satunya jalur pengurangan/pemeriksaan stok obat dan bahan habis pakai.
 *
 * Modul Clinic TIDAK menyimpan saldo stok sendiri. Inventory adalah sumber
 * kebenaran; kontrak ini mengembalikan hasil berstatus sehingga klinik tahu
 * apakah penyerahan obat berhasil, dan tetap mencatat pemakaian klinisnya.
 */
interface InventoryProviderInterface
{
    /** Saldo tersedia untuk sebuah item obat (null bila obat tidak dipetakan). */
    public function availableQuantity(?int $inventoryItemId): ?float;

    /**
     * Batch yang boleh dipakai menurut FEFO (kedaluwarsa terdekat lebih dahulu).
     *
     * @return list<array{batch_id: ?int, batch_number: ?string, expiry_date: ?string, quantity: float}>
     */
    public function usableBatches(int $medicationId): array;

    /**
     * Kurangi stok karena pemakaian klinis. WAJIB dipanggil di dalam transaksi
     * pemanggil agar penyerahan obat dan pengurangan stok tidak terpisah.
     *
     * Hasil:
     * - SUCCESS            → stok berkurang, `movement_id` terisi
     * - INSUFFICIENT_STOCK → stok tidak cukup, tidak ada perubahan
     * - EXPIRED            → batch kedaluwarsa, tidak ada perubahan
     * - NOT_AVAILABLE      → obat belum dipetakan ke item Inventory
     * - FAILED             → kegagalan lain (mis. item nonaktif)
     *
     * @return array{result: InventoryStockResult, movement_id: ?int, reference: ?string, message: string, quantity_after: ?float}
     */
    public function consume(
        ?int $inventoryItemId,
        float $quantity,
        string $reason,
        ?string $reference,
        ?int $actorId,
    ): array;

    /** Item dengan saldo di bawah minimum (untuk kartu peringatan dashboard). */
    public function lowStockItems(int $limit = 20): array;

    /** Item yang kedaluwarsa atau mendekati kedaluwarsa dalam N hari. */
    public function expiringItems(int $days, int $limit = 20): array;
}
