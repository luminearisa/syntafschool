<?php

namespace App\Http\Resources;

use App\Models\FinanceInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Invoice (§16). `paid_amount`/`remaining_amount`/`status` hanya bergerak lewat
 * payment terverifikasi (§21) — dibaca-saja di resource ini.
 */
class FinanceInvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'invoice_number' => $this->invoice_number,
            'billing_id' => $this->billing_id !== null ? (int) $this->billing_id : null,
            'billing_number' => $this->whenLoaded('billing', fn (): ?string => $this->billing?->billing_number),
            'customer_type' => $this->customer_type,
            'customer_id' => (int) $this->customer_id,
            'student_id' => $this->student_id !== null ? (int) $this->student_id : null,
            'student_name' => $this->whenLoaded('student', fn (): ?string => $this->student?->full_name),
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'cost_center_id' => $this->cost_center_id !== null ? (int) $this->cost_center_id : null,
            'cost_center_name' => $this->whenLoaded('costCenter', fn (): ?string => $this->costCenter?->name),
            'subtotal' => (float) $this->subtotal,
            'discount_amount' => (float) $this->discount_amount,
            'tax' => (float) $this->tax,
            'total' => (float) $this->total,
            'paid_amount' => (float) $this->paid_amount,
            'written_off_amount' => (float) $this->written_off_amount,
            'remaining_amount' => (float) $this->remaining_amount,
            'status' => $this->status,
            'status_label' => FinanceInvoice::STATUS_LABELS[$this->status] ?? $this->status,
            // Status AR (§21) — bisa `written_off` walau status dokumen `paid`.
            'receivable_status' => $this->resource->receivableStatus(),
            'is_overdue' => $this->resource->isOverdue(),
            'notes' => $this->notes,
            'items' => FinanceInvoiceItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
