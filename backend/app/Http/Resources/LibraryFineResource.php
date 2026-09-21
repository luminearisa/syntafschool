<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LibraryFineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'library_id' => $this->library_id,
            'member_id' => $this->member_id,
            'loan_id' => $this->loan_id,
            'item_id' => $this->item_id,

            'type' => $this->type,
            'amount' => (float) $this->amount,
            'paid_amount' => (float) $this->paid_amount,
            'waived_amount' => (float) $this->waived_amount,
            'outstanding_amount' => $this->outstandingAmount(),
            'status' => $this->status,

            'assessed_at' => $this->assessed_at?->toDateString(),
            'overdue_days' => $this->overdue_days,
            'waived_reason' => $this->waived_reason,
            'waived_at' => $this->waived_at?->toIso8601String(),
            'notes' => $this->notes,

            'member' => $this->whenLoaded('member', fn () => $this->member ? [
                'id' => $this->member->id,
                'member_number' => $this->member->member_number,
                'name' => $this->member->display_name,
            ] : null),

            'loan' => $this->whenLoaded('loan', fn () => $this->loan ? [
                'id' => $this->loan->id,
                'loan_code' => $this->loan->loan_code,
                'due_at' => $this->loan->due_at?->toDateString(),
                'returned_on' => $this->loan->returned_on?->toDateString(),
            ] : null),

            'item' => $this->whenLoaded('item', fn () => $this->item ? [
                'id' => $this->item->id,
                'item_code' => $this->item->item_code,
                'barcode' => $this->item->barcode,
            ] : null),

            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'paid_at' => $payment->paid_at?->toDateString(),
                'payment_method' => $payment->paymentMethod?->name,
                'reference' => $payment->reference,
                'notes' => $payment->notes,
            ])->values()),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}