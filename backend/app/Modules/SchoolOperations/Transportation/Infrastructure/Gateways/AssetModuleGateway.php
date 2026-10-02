<?php

namespace App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\AssetGatewayInterface;

class AssetModuleGateway implements AssetGatewayInterface
{
    use GatewaySupport;

    public function find(int $assetId): ?array
    {
        $asset = $this->row('assets', $assetId);
        if (! $asset) {
            return null;
        }

        return [
            'id' => (int) $asset['id'],
            'asset_code' => $asset['asset_code'] ?? null,
            'name' => $asset['name'] ?? null,
            'status' => $asset['status'] ?? null,
            'condition' => $asset['condition'] ?? null,
            'brand' => $asset['brand'] ?? null,
            'model' => $asset['model'] ?? null,
            'serial_number' => $asset['serial_number'] ?? null,
            'next_maintenance_at' => $asset['next_maintenance_at'] ?? null,
            'last_maintenance_at' => $asset['last_maintenance_at'] ?? null,
            'school_id' => $asset['school_id'] ?? null,
            'vendor_id' => $asset['vendor_id'] ?? null,
            'source' => 'assets',
        ];
    }

    public function equipmentAvailability(int $assetId): array
    {
        $asset = $this->row('assets', $assetId);
        if (! $asset) {
            return ['available' => false, 'reason' => 'Aset tidak ditemukan pada Asset Management.'];
        }

        $status = strtoupper((string) ($asset['status'] ?? ''));

        return [
            'available' => ! in_array($status, ['LOST', 'DISPOSED', 'RETIRED'], true),
            'asset_status' => $asset['status'] ?? null,
            'condition' => $asset['condition'] ?? null,
        ];
    }

    public function available(): bool
    {
        return $this->has('assets');
    }
}
