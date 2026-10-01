<?php

namespace App\Modules\Activity\Infrastructure\Providers;

/** Tidak ada master aset terpisah — memperlakukan master inventaris sebagai aset. */
class InventoryAssetProvider implements \App\Modules\Activity\Domain\Contracts\AssetProviderInterface
{
    public function __construct(private readonly \App\Modules\Activity\Domain\Contracts\InventoryProviderInterface $inventory) {}

    public function info(int $assetId): ?array
    {
        return $this->inventory->availability($assetId);
    }

    public function lend(array $payload): array
    {
        return $this->inventory->issue([
            'item_id' => $payload['asset_id'],
            'quantity' => $payload['quantity'] ?? 1,
            'reference' => $payload['reference'] ?? null,
            'actor_user_id' => $payload['actor_user_id'] ?? null,
        ]);
    }

    public function collect(array $payload): array
    {
        return $this->inventory->receive([
            'item_id' => $payload['asset_id'],
            'quantity' => $payload['quantity'] ?? 1,
            'reference' => $payload['reference'] ?? null,
            'actor_user_id' => $payload['actor_user_id'] ?? null,
        ]);
    }
}
