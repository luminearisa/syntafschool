<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Inventory (§8, §12). Al-Qur'an, buku, dan perlengkapan habis pakai.
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface InventoryGatewayInterface
{
    /** Data item inventaris. */
    public function find(int $itemId): ?array;

    /** Pengeluaran item inventaris. */
    public function issue(array $payload): array;

    /** Stok tersedia. */
    public function stock(int $itemId): ?int;

    /** Ketersediaan modul inventaris. */
    public function available(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
