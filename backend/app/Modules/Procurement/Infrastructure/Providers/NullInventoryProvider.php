<?php

namespace App\Modules\Procurement\Infrastructure\Providers;

use App\Modules\Procurement\Domain\Contracts\InventoryProviderInterface;
use App\Modules\Procurement\Domain\Support\IntegrationResult;

/**
 * Implementasi bawaan bila modul Inventory belum terpasang.
 *
 * Penerimaan tetap dicatat Procurement; pencatatan stok dicatat sebagai PENDING
 * di `proc_integrations` sehingga tidak hilang (§25, §52.13).
 */
class NullInventoryProvider implements InventoryProviderInterface
{
    public const CODE = 'PROVIDER_NOT_INSTALLED';

    public function receive(array $payload): IntegrationResult
    {
        return IntegrationResult::failure(
            'Modul Inventory belum terpasang; penerimaan stok dicatat sebagai PENDING.',
            self::CODE,
        );
    }

    public function stockOnHand(string $itemReference, ?int $warehouseId = null): ?array
    {
        return null;
    }
}
