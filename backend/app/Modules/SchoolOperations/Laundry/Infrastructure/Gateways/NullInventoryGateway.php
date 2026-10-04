<?php

namespace App\Modules\SchoolOperations\Laundry\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Laundry\Domain\Contracts\InventoryProviderInterface;

/**
 * Inventaris (§35): pemakaian bahan (deterjen dsb.) diteruskan ke modul
 * Inventory bila kontraknya terpasang; bila tidak, konsumsi hanya tercatat
 * pada log pemrosesan lokal dan gateway menyatakan diri tidak tersedia.
 */
class NullInventoryGateway implements InventoryProviderInterface
{
    use GatewaySupport;

    public function recordConsumption(array $payload): array
    {
        $provider = $this->delegate('App\Modules\Activity\Domain\Contracts\InventoryProviderInterface');

        if ($provider && method_exists($provider, 'recordConsumption')) {
            try {
                return (array) $provider->recordConsumption($payload);
            } catch (\Throwable $exception) {
                return ['deducted' => false, 'reason' => $exception->getMessage()];
            }
        }

        return ['deducted' => false, 'reason' => 'Modul inventory belum tersedia.'];
    }

    public function available(): bool
    {
        return $this->delegate('App\Modules\Activity\Domain\Contracts\InventoryProviderInterface') !== null;
    }
}
