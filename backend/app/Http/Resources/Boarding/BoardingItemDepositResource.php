<?php

namespace App\Http\Resources\Boarding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Titipan barang santri beserta ringkasan isinya. */
class BoardingItemDepositResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var \App\Models\BoardingItemDeposit $deposit */
        $deposit = $this->resource;

        $items = $deposit->relationLoaded('items') ? $deposit->items : collect();

        return [
            'id' => (int) $deposit->id,
            'student_id' => (int) $deposit->student_id,
            'student' => $deposit->relationLoaded('student') && $deposit->student !== null ? [
                'id' => (int) $deposit->student->id,
                'full_name' => $deposit->student->full_name,
                'nis' => $deposit->student->nis,
            ] : null,
            'received_by' => $deposit->relationLoaded('receivedBy') && $deposit->receivedBy !== null ? [
                'id' => (int) $deposit->receivedBy->id,
                'name' => $deposit->receivedBy->name,
            ] : null,
            'closed_by' => $deposit->relationLoaded('closedBy') && $deposit->closedBy !== null ? [
                'id' => (int) $deposit->closedBy->id,
                'name' => $deposit->closedBy->name,
            ] : null,
            'deposited_at' => $deposit->deposited_at?->toIso8601String(),
            'returned_at' => $deposit->returned_at?->toIso8601String(),
            'status' => (string) $deposit->status,
            'status_label' => $deposit->statusLabel(),
            'notes' => $deposit->notes,
            'items_count' => $items->count(),
            'borrowed_count' => $items->where('status', \App\Models\BoardingItemDepositItem::STATUS_BORROWED)->count(),
            'items' => BoardingItemDepositItemResource::collection($items),
            'created_at' => $deposit->created_at?->toIso8601String(),
        ];
    }
}
