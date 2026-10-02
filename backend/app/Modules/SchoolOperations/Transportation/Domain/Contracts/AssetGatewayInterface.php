<?php

namespace App\Modules\SchoolOperations\Transportation\Domain\Contracts;

/** Aset: kendaraan selalu berasal dari Asset Management, bukan master kedua (§6). */
interface AssetGatewayInterface
{
    public function find(int $assetId): ?array;

    public function equipmentAvailability(int $assetId): array;

    public function available(): bool;
}
