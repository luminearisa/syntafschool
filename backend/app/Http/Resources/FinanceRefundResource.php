<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Refund (§29). `status_label` untuk UI; `finance_transaction_id` menaut ke
 * jurnal balik saat refund diselesaikan — jejak audit, payment asli tak dihapus.
 */
class FinanceRefundResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'refund_number' => $this->refund_number,
            'payment_id' => (int) $this->payment_id,
            'payment_number' => $this->whenLoaded('payment', fn (): ?string => $this->payment?->payment_number),
            'amount' => (float) $this->amount,
            'reason' => $this->reason,
            'status' => $this->status,
            'status_label' => $this->resource->statusLabel(),
            'cash_account_id' => $this->cash_account_id !== null ? (int) $this->cash_account_id : null,
            'cash_account_name' => $this->whenLoaded('cashAccount', fn (): ?string => $this->cashAccount?->name),
            'bank_account_id' => $this->bank_account_id !== null ? (int) $this->bank_account_id : null,
            'bank_account_name' => $this->whenLoaded('bankAccount', fn (): ?string => $this->bankAccount?->name),
            'requested_by' => $this->requested_by !== null ? (int) $this->requested_by : null,
            'requested_by_name' => $this->whenLoaded('requestedBy', fn (): ?string => $this->requestedBy?->name),
            'approved_by' => $this->approved_by !== null ? (int) $this->approved_by : null,
            'approved_by_name' => $this->whenLoaded('approvedBy', fn (): ?string => $this->approvedBy?->name),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'reject_reason' => $this->reject_reason,
            'finance_transaction_id' => $this->finance_transaction_id !== null ? (int) $this->finance_transaction_id : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
