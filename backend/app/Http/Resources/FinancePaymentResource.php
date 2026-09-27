<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Payment (§19–§22). `status_label`/`method_label` untuk UI; saldo invoice
 * hanya bergerak saat payment confirmed (§21) sehingga field ini dibaca-saja.
 */
class FinancePaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'payment_number' => $this->payment_number,
            'invoice_id' => $this->invoice_id !== null ? (int) $this->invoice_id : null,
            'invoice_number' => $this->whenLoaded('invoice', fn (): ?string => $this->invoice?->invoice_number),
            'billing_id' => $this->billing_id !== null ? (int) $this->billing_id : null,
            'billing_number' => $this->whenLoaded('billing', fn (): ?string => $this->billing?->billing_number),
            'customer_type' => $this->customer_type,
            'customer_id' => (int) $this->customer_id,
            'student_id' => $this->student_id !== null ? (int) $this->student_id : null,
            'student_name' => $this->whenLoaded('student', fn (): ?string => $this->student?->full_name),
            'amount' => (float) $this->amount,
            'method' => $this->method,
            'method_label' => $this->resource->methodLabel(),
            'status' => $this->status,
            'status_label' => $this->resource->statusLabel(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'gateway' => $this->gateway,
            'gateway_transaction_id' => $this->gateway_transaction_id,
            'reference_number' => $this->reference_number,
            'proof_path' => $this->proof_path,
            'cash_account_id' => $this->cash_account_id !== null ? (int) $this->cash_account_id : null,
            'cash_account_name' => $this->whenLoaded('cashAccount', fn (): ?string => $this->cashAccount?->name),
            'bank_account_id' => $this->bank_account_id !== null ? (int) $this->bank_account_id : null,
            'bank_account_name' => $this->whenLoaded('bankAccount', fn (): ?string => $this->bankAccount?->name),
            'cashier_session_id' => $this->cashier_session_id !== null ? (int) $this->cashier_session_id : null,
            'cost_center_id' => $this->cost_center_id !== null ? (int) $this->cost_center_id : null,
            'verified_by' => $this->verified_by !== null ? (int) $this->verified_by : null,
            'verified_by_name' => $this->whenLoaded('verifiedBy', fn (): ?string => $this->verifiedBy?->name),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'reject_reason' => $this->reject_reason,
            'notes' => $this->notes,
            'receipt' => new FinanceReceiptResource($this->whenLoaded('receipt')),
            'refunds' => FinanceRefundResource::collection($this->whenLoaded('refunds')),
            'allocations' => $this->whenLoaded('allocations', fn (): array => $this->allocations->map(fn ($allocation): array => [
                'id' => (int) $allocation->id,
                'invoice_id' => (int) $allocation->invoice_id,
                'invoice_number' => $allocation->relationLoaded('invoice') ? $allocation->invoice?->invoice_number : null,
                'amount' => (float) $allocation->amount,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
