<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Purchase order (§38) beserta barisnya (termasuk kuantitas diterima).
 */
class FinancePurchaseOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'po_number' => $this->po_number,
            'vendor_id' => (int) $this->vendor_id,
            'vendor_name' => $this->whenLoaded('vendor', fn (): ?string => $this->vendor?->name),
            'purchase_request_id' => $this->purchase_request_id !== null ? (int) $this->purchase_request_id : null,
            'purchase_request_number' => $this->whenLoaded('purchaseRequest', fn (): ?string => $this->purchaseRequest?->request_number),
            'po_date' => $this->po_date?->toDateString(),
            'cost_center_id' => $this->cost_center_id !== null ? (int) $this->cost_center_id : null,
            'cost_center_name' => $this->whenLoaded('costCenter', fn (): ?string => $this->costCenter?->name),
            'account_id' => $this->account_id !== null ? (int) $this->account_id : null,
            'subtotal' => (float) $this->subtotal,
            'tax' => (float) $this->tax,
            'total' => (float) $this->total,
            'status' => $this->status,
            'status_label' => $this->resource->statusLabel(),
            'approved_by' => $this->approved_by !== null ? (int) $this->approved_by : null,
            'approved_by_name' => $this->whenLoaded('approvedBy', fn (): ?string => $this->approvedBy?->name),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'notes' => $this->notes,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'id' => (int) $item->id,
                'item_name' => $item->item_name,
                'quantity' => (int) $item->quantity,
                'received_quantity' => (int) $item->received_quantity,
                'price' => (float) $item->price,
                'total' => (float) $item->total,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
