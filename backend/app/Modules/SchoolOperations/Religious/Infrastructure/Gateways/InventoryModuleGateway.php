<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\InventoryGatewayInterface;
use Illuminate\Support\Facades\DB;

/**
 * Inventory (§8): Al-Qur'an, buku, dan perlengkapan masjid.
 */
class InventoryModuleGateway implements InventoryGatewayInterface
{
    use GatewaySupport;

    protected function provider(): ?object
    {
        return $this->delegate('App\\Modules\\Activity\\Domain\\Contracts\\InventoryProviderInterface')
            ?? $this->delegate('App\\Modules\\Asset\\Domain\\Contracts\\InventoryProviderInterface')
            ?? $this->delegate('App\\Modules\\Clinic\\Domain\\Contracts\\InventoryProviderInterface');
    }

    protected function table(): ?string
    {
        return $this->firstTable(['inventory_items', 'inventory_stocks', 'inventories']);
    }

    public function find(int $itemId): ?array
    {
        $table = $this->table();

        return $table ? $this->row($table, $itemId) : null;
    }

    public function issue(array $payload): array
    {
        if ($provider = $this->provider()) {
            try {
                foreach (['issue', 'consume', 'dispatch'] as $method) {
                    if (method_exists($provider, $method)) {
                        return (array) $provider->{$method}($payload);
                    }
                }
            } catch (\Throwable $e) {
                return ['issued' => false, 'error' => $e->getMessage()];
            }
        }

        return ['issued' => false, 'reason' => $this->unavailableReason()];
    }

    public function stock(int $itemId): ?int
    {
        $table = $this->table();
        if ($table === null) {
            return null;
        }

        $columns = $this->columns($table);
        $column = $this->pick($columns, ['stock', 'quantity', 'available_quantity']);
        if ($column === null) {
            return null;
        }

        $value = DB::table($table)->where('id', $itemId)->value($column);

        return $value === null ? null : (int) $value;
    }

    public function available(): bool
    {
        return $this->provider() !== null || $this->table() !== null;
    }

    public function unavailableReason(): ?string
    {
        return $this->available() ? null : 'Modul Inventory tidak tersedia pada instalasi ini.';
    }
}
