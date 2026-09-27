<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Receipt / kuitansi (§24, §25). `is_cancelled` menandai pembatalan — kuitansi
 * tidak pernah dihapus, hanya dibatalkan beserta alasan dan waktunya.
 */
class FinanceReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'receipt_number' => $this->receipt_number,
            'payment_id' => (int) $this->payment_id,
            'payment_number' => $this->whenLoaded('payment', fn (): ?string => $this->payment?->payment_number),
            'invoice_id' => $this->invoice_id !== null ? (int) $this->invoice_id : null,
            'invoice_number' => $this->whenLoaded('invoice', fn (): ?string => $this->invoice?->invoice_number),
            'customer_type' => $this->customer_type,
            'customer_id' => (int) $this->customer_id,
            'student_id' => $this->student_id !== null ? (int) $this->student_id : null,
            'student_name' => $this->whenLoaded('student', fn (): ?string => $this->student?->full_name),
            'amount' => (float) $this->amount,
            'method' => $this->method,
            'received_at' => $this->received_at?->toIso8601String(),
            'is_cancelled' => (bool) $this->is_cancelled,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'reversal_receipt_id' => $this->reversal_receipt_id !== null ? (int) $this->reversal_receipt_id : null,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
