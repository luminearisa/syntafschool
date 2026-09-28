<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'item_category_id' => $this->item_category_id === null ? null : (int) $this->item_category_id,
            'category_name' => $this->whenLoaded('category', fn () => $this->category?->name),
            'unit_id' => $this->unit_id === null ? null : (int) $this->unit_id,
            'unit_name' => $this->whenLoaded('unit', fn () => $this->unit?->name),
            'unit_symbol' => $this->whenLoaded('unit', fn () => $this->unit?->symbol),
            'storage_location_id' => $this->storage_location_id === null ? null : (int) $this->storage_location_id,
            'location_name' => $this->whenLoaded('storageLocation', fn () => $this->storageLocation?->name),
            'room_name' => $this->whenLoaded('storageLocation', fn () => $this->storageLocation?->room?->name),
            'serial_number' => $this->serial_number,
            'quantity' => (float) $this->quantity,
            'minimum_quantity' => (float) $this->minimum_quantity,
            'is_low_stock' => $this->resource->isLowStock(),
            'unit_cost' => $this->unit_cost === null ? null : (float) $this->unit_cost,
            'purchase_date' => $this->purchase_date?->toDateString(),
            'condition' => $this->condition,
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'movements' => $this->whenLoaded('movements', fn () => $this->movements->map(fn ($movement) => [
                'id' => (int) $movement->id,
                'movement_type' => $movement->movement_type,
                'quantity' => (float) $movement->quantity,
                'quantity_before' => (float) $movement->quantity_before,
                'quantity_after' => (float) $movement->quantity_after,
                'reason' => $movement->reason,
                'reference' => $movement->reference,
                'created_by_name' => $movement->createdBy?->name,
                'created_at' => $movement->created_at?->toIso8601String(),
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
