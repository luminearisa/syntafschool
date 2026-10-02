<?php

namespace App\Modules\SchoolOperations\Transportation\Domain\Contracts;

/** Pemeliharaan: status kendaraan & work order (§57). */
interface MaintenanceGatewayInterface
{
    public function statusForAsset(?int $assetId, ?int $vehicleId = null): array;

    public function isBlocked(?int $assetId, ?int $vehicleId = null): bool;

    public function createRequest(array $payload): ?array;

    public function openWorkOrders(?int $assetId, ?int $vehicleId = null): array;

    public function available(): bool;
}
