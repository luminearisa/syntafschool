<?php

namespace App\Http\Resources;

use App\Models\FinanceBilling;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Billing (§13–§15). `status_label` untuk UI; `items` = baris tagihan; total &
 * sisa dihitung service, bukan di sini. `invoice` dimuat saat invoice diterbitkan.
 */
class FinanceBillingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'billing_number' => $this->billing_number,
            'customer_type' => $this->customer_type,
            'customer_id' => (int) $this->customer_id,
            'student_id' => $this->student_id !== null ? (int) $this->student_id : null,
            'student_name' => $this->whenLoaded('student', fn (): ?string => $this->student?->full_name),
            'source' => $this->source,
            'reference' => $this->reference,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'cost_center_id' => $this->cost_center_id !== null ? (int) $this->cost_center_id : null,
            'cost_center_name' => $this->whenLoaded('costCenter', fn (): ?string => $this->costCenter?->name),
            'subtotal' => (float) $this->subtotal,
            'discount_amount' => (float) $this->discount_amount,
            'scholarship_amount' => (float) $this->scholarship_amount,
            'tax' => (float) $this->tax,
            'total' => (float) $this->total,
            'paid_amount' => (float) $this->paid_amount,
            'remaining_amount' => (float) $this->remaining_amount,
            'status' => $this->status,
            'status_label' => FinanceBilling::STATUS_LABELS[$this->status] ?? $this->status,
            'invoice_id' => $this->invoice_id !== null ? (int) $this->invoice_id : null,
            'invoice_number' => $this->whenLoaded('invoice', fn (): ?string => $this->invoice?->invoice_number),
            'notes' => $this->notes,
            'items' => FinanceBillingItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
