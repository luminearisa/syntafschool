<?php

namespace App\Modules\Activity\Domain\Contracts;

/**
 * Inventaris — seragam, bola, konsumabel, instrumen (§63).
 */
interface InventoryProviderInterface
{
    public function availability(int $itemId): ?array;

    /** Serahkan barang; stok berkurang di modul Inventory. */
    public function issue(array $payload): array;

    /** Terima pengembalian barang. */
    public function receive(array $payload): array;
}
