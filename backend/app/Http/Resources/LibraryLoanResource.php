<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LibraryLoanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'library_id' => $this->library_id,
            'loan_code' => $this->loan_code,
            'member_id' => $this->member_id,
            'item_id' => $this->item_id,
            'bibliography_id' => $this->bibliography_id,

            'borrowed_at' => $this->borrowed_at?->toDateString(),
            'due_at' => $this->due_at?->toDateString(),
            'returned_on' => $this->returned_on?->toDateString(),
            'returned_at' => $this->returned_at?->toIso8601String(),
            'renewed_count' => $this->renewed_count,
            'status' => $this->status,
            'is_open' => $this->isOpen(),
            'overdue_days' => $this->overdueDays(),
            'is_overdue' => $this->overdueDays() > 0,

            'notes' => $this->notes,
            'borrowed_by' => $this->borrowed_by,
            'returned_by' => $this->returned_by,

            'member' => $this->whenLoaded('member', fn () => $this->member ? [
                'id' => $this->member->id,
                'member_number' => $this->member->member_number,
                'name' => $this->member->display_name,
            ] : null),

            'item' => $this->whenLoaded('item', fn () => $this->item ? [
                'id' => $this->item->id,
                'item_code' => $this->item->item_code,
                'barcode' => $this->item->barcode,
                'accession_number' => $this->item->accession_number,
            ] : null),

            'bibliography' => $this->whenLoaded('bibliography', fn () => $this->bibliography ? [
                'id' => $this->bibliography->id,
                'title' => $this->bibliography->title,
                'slug' => $this->bibliography->slug,
                'control_number' => $this->bibliography->control_number,
            ] : null),

            'renewals' => $this->whenLoaded('renewals', fn () => $this->renewals->map(fn ($renewal) => [
                'id' => $renewal->id,
                'previous_due_at' => $renewal->previous_due_at?->toDateString(),
                'new_due_at' => $renewal->new_due_at?->toDateString(),
            ])->values()),

            'fines' => $this->whenLoaded('fines', fn () => $this->fines->map(fn ($fine) => [
                'id' => $fine->id,
                'type' => $fine->type,
                'amount' => (float) $fine->amount,
                'outstanding' => $fine->outstandingAmount(),
                'status' => $fine->status,
            ])->values()),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}