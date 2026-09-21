<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LibraryAcquisitionRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'library_id' => $this->library_id,
            'code' => $this->code,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier_name,
            'status' => $this->status,
            'allowed_transitions' => $this->allowedTransitions(),

            'requested_at' => $this->requested_at?->toDateString(),
            'expected_at' => $this->expected_at?->toDateString(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'total_amount' => (float) $this->total_amount,
            'notes' => $this->notes,
            'items_count' => (int) ($this->items_count ?? 0),

            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier ? [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
            ] : null),

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'title' => $item->title,
                'isbn' => $item->isbn,
                'quantity' => $item->quantity,
                'received_quantity' => $item->received_quantity,
                'unit_price' => (float) $item->unit_price,
                'subtotal' => $item->subtotal(),
                'bibliography_id' => $item->bibliography_id,
                'collection_type_id' => $item->collection_type_id,
                'notes' => $item->notes,
            ])->values()),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}