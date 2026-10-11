<?php

namespace App\Http\Resources\Boarding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Satu barang titipan + status terkini + pinjaman terbuka. */
class BoardingItemDepositItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var \App\Models\BoardingItemDepositItem $item */
        $item = $this->resource;

        return [
            'id' => (int) $item->id,
            'deposit_id' => (int) $item->deposit_id,
            'item_type' => (string) $item->item_type,
            'item_type_label' => $item->itemTypeLabel(),
            'item_name' => (string) $item->item_name,
            'status' => (string) $item->status,
            'status_label' => $item->statusLabel(),
            'notes' => $item->notes,
            'current_loan' => $item->relationLoaded('currentLoan') && $item->currentLoan !== null ? [
                'id' => (int) $item->currentLoan->id,
                'borrowed_at' => $item->currentLoan->borrowed_at?->toIso8601String(),
                'notes' => $item->currentLoan->notes,
            ] : null,
            'loans' => $item->relationLoaded('loans') ? $item->loans->map(fn ($loan): array => [
                'id' => (int) $loan->id,
                'borrowed_at' => $loan->borrowed_at?->toIso8601String(),
                'returned_at' => $loan->returned_at?->toIso8601String(),
                'notes' => $loan->notes,
                'processed_by' => $loan->processedBy?->name,
            ])->values()->all() : null,
            'created_at' => $item->created_at?->toIso8601String(),
        ];
    }
}
