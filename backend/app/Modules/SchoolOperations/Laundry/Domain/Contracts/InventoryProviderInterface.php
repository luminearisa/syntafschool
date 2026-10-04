<?php

namespace App\Modules\SchoolOperations\Laundry\Domain\Contracts;

/**
 * Inventaris (§35). Pemakaian bahan (deterjen, pewangi, gas) dapat dicatat
 * ke Inventory bila modulnya terpasang; bila tidak, hanya tercatat di log
 * pemrosesan lokal.
 */
interface InventoryProviderInterface
{
    /**
     * @param  array{item_code:string, quantity:float, unit?:string, reference?:string}  $payload
     * @return array{deducted:bool, reason?:string}
     */
    public function recordConsumption(array $payload): array;

    public function available(): bool;
}
