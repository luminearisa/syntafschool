<?php

namespace App\Modules\SchoolOperations\Laundry\Domain\Contracts;

/**
 * Aset (§34). Mesin laundry dapat ditautkan ke register aset bila modul
 * Asset terpasang (status pemeliharaan dibaca dari sana).
 */
interface AssetProviderInterface
{
    /** @return array{asset_id:int, code:?string, name:?string, status:?string, maintenance_state:?string}|null */
    public function snapshot(int $assetId): ?array;

    public function available(): bool;
}
