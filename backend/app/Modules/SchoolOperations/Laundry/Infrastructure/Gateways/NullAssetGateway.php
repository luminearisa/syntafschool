<?php

namespace App\Modules\SchoolOperations\Laundry\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Laundry\Domain\Contracts\AssetProviderInterface;

/**
 * Aset (§34): delegasi ke modul Asset bila terpasang; tanpa itu mesin laundry
 * dikelola lokal di `laundry_machines` tanpa tautan register aset.
 */
class NullAssetGateway implements AssetProviderInterface
{
    use GatewaySupport;

    public function snapshot(int $assetId): ?array
    {
        $provider = $this->delegate('App\Modules\Asset\Domain\Contracts\AssetProviderInterface')
            ?? $this->delegate('App\Modules\Activity\Domain\Contracts\AssetProviderInterface');

        if ($provider && method_exists($provider, 'snapshot')) {
            try {
                $result = $provider->snapshot($assetId);

                return is_array($result) ? $result : null;
            } catch (\Throwable) {
                return null;
            }
        }

        return $this->row('assets', $assetId);
    }

    public function available(): bool
    {
        return $this->has('assets');
    }
}
