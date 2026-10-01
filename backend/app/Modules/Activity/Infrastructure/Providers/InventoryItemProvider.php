<?php

namespace App\Modules\Activity\Infrastructure\Providers;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Modules\Activity\Domain\Contracts\InventoryProviderInterface;
use Illuminate\Support\Facades\DB;

/**
 * Penyerahan dan pengembalian barang inventaris (§63, §100).
 *
 * Stok hanya berubah di sini — satu jalur, dengan catatan mutasi
 * (`inventory_movements`) yang menyebut nomor permintaan sebagai alasannya.
 */
class InventoryItemProvider implements InventoryProviderInterface
{
    public function availability(int $itemId): ?array
    {
        $item = InventoryItem::query()->find($itemId);

        if ($item === null) {
            return null;
        }

        return [
            'id' => (int) $item->id,
            'name' => (string) $item->name,
            'sku' => $item->sku,
            'quantity' => (int) $item->quantity,
            'condition' => $item->condition,
        ];
    }

    public function issue(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            $item = InventoryItem::query()->lockForUpdate()->find($payload['item_id']);

            if ($item === null) {
                return ['status' => 'REJECTED', 'reason' => 'Barang tidak ditemukan.'];
            }

            $quantity = (int) ($payload['quantity'] ?? 1);

            if ((int) $item->quantity < $quantity) {
                return [
                    'status' => 'REJECTED',
                    'reason' => 'Stok tidak mencukupi (tersedia '.$item->quantity.').',
                ];
            }

            $before = (int) $item->quantity;
            $item->quantity = $before - $quantity;
            $item->save();

            InventoryMovement::query()->create([
                'inventory_item_id' => $item->id,
                'movement_type' => 'OUT',
                'quantity' => $quantity,
                'quantity_before' => $before,
                'quantity_after' => (int) $item->quantity,
                'reason' => 'Peminjaman aktivitas siswa',
                'reference' => $payload['reference'] ?? null,
                'created_by' => $payload['actor_user_id'] ?? null,
            ]);

            return [
                'status' => 'ISSUED',
                'reference' => 'inventory:'.$item->id.':'.$quantity,
                'remaining_stock' => (int) $item->quantity,
            ];
        });
    }

    public function receive(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            $item = InventoryItem::query()->lockForUpdate()->find($payload['item_id']);

            if ($item === null) {
                return ['status' => 'REJECTED', 'reason' => 'Barang tidak ditemukan.'];
            }

            $quantity = (int) ($payload['quantity'] ?? 1);
            $before = (int) $item->quantity;
            $item->quantity = $before + $quantity;
            $item->save();

            InventoryMovement::query()->create([
                'inventory_item_id' => $item->id,
                'movement_type' => 'IN',
                'quantity' => $quantity,
                'quantity_before' => $before,
                'quantity_after' => (int) $item->quantity,
                'reason' => 'Pengembalian peralatan aktivitas',
                'reference' => $payload['reference'] ?? null,
                'created_by' => $payload['actor_user_id'] ?? null,
            ]);

            return ['status' => 'RETURNED', 'stock' => (int) $item->quantity];
        });
    }
}
