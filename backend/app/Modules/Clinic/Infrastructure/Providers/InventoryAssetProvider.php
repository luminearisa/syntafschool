<?php

namespace App\Modules\Clinic\Infrastructure\Providers;

use App\Models\InventoryItem;
use App\Modules\Clinic\Domain\Contracts\AssetProviderInterface;

/**
 * Alat kesehatan memakai master aset/inventaris ERP.
 *
 * Klinik hanya merujuk (`device_ref`) ke item aset; tidak ada tabel alat kedua.
 * Bila ERP kelak punya modul Asset khusus, cukup ganti binding kontrak ini.
 */
class InventoryAssetProvider implements AssetProviderInterface
{
    public function equipmentForClinic(?int $clinicId = null, int $limit = 50): array
    {
        return InventoryItem::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNotNull('serial_number')
                ->orWhere('name', 'like', '%termometer%')
                ->orWhere('name', 'like', '%tensi%')
                ->orWhere('name', 'like', '%oksimeter%')
                ->orWhere('name', 'like', '%timbangan%')
                ->orWhere('name', 'like', '%nebulizer%')
                ->orWhere('name', 'like', '%p3k%'))
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (InventoryItem $item): array => [
                'id' => $item->id,
                'sku' => $item->sku,
                'name' => $item->name,
                'serial_number' => $item->serial_number,
                'condition' => $item->condition,
                'location' => $item->storageLocation?->name,
            ])
            ->all();
    }

    public function isValid(string $assetRef): bool
    {
        return InventoryItem::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('serial_number', $assetRef)->orWhere('sku', $assetRef))
            ->exists();
    }
}
