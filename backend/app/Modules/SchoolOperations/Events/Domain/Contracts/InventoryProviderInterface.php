<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * InventoryProviderInterface
 *
 * Inventaris & barang habis pakai (§47).
 *
 * Modul Event hanya melakukan orkestrasi: seluruh data pokok tetap dimiliki
 * modul asalnya. Bila sumber belum terpasang, implementasi wajib menyatakan
 * `available() === false` beserta alasannya — bukan mengarang data.
 */
interface InventoryProviderInterface
{
    /** Detail ringkas satu item. */
    public function find(int $itemId): ?array;

    /** Stok tersedia. */
    public function stock(int $itemId): array;

    /** Mengajukan permintaan barang. */
    public function request(array $payload): array;

    /** Mengeluarkan barang. */
    public function issue(int $requestId, array $payload = []): array;

    /** Apakah sumber inventaris tersedia. */
    public function isAvailable(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
