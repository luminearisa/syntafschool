<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Purchase request (§37) beserta barisnya.
 */
class FinancePurchaseRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'request_number' => $this->request_number,
            'requester_id' => $this->requester_id !== null ? (int) $this->requester_id : null,
            'requester_name' => $this->whenLoaded('requester', fn (): ?string => $this->requester?->name),
            'cost_center_id' => $this->cost_center_id !== null ? (int) $this->cost_center_id : null,
            'cost_center_name' => $this->whenLoaded('costCenter', fn (): ?string => $this->costCenter?->name),
            'description' => $this->description,
            'needed_date' => $this->needed_date?->toDateString(),
            'status' => $this->status,
            'approved_by' => $this->approved_by !== null ? (int) $this->approved_by : null,
            'approved_by_name' => $this->whenLoaded('approvedBy', fn (): ?string => $this->approvedBy?->name),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'reject_reason' => $this->reject_reason,
            'total' => $this->whenLoaded('items', fn (): float => round((float) $this->items->sum('total'), 2)),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'id' => (int) $item->id,
                'item_name' => $item->item_name,
                'quantity' => (int) $item->quantity,
                'estimated_price' => (float) $item->estimated_price,
                'total' => (float) $item->total,
                'account_id' => $item->account_id !== null ? (int) $item->account_id : null,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
