<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LibraryReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'library_id' => $this->library_id,
            'reservation_code' => $this->reservation_code,
            'member_id' => $this->member_id,
            'bibliography_id' => $this->bibliography_id,
            'item_id' => $this->item_id,

            'status' => $this->status,
            'is_open' => $this->isOpen(),
            'queue_position' => $this->queue_position,
            'reserved_at' => $this->reserved_at?->toDateString(),
            'available_at' => $this->available_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toDateString(),
            'picked_up_at' => $this->picked_up_at?->toDateString(),
            'cancelled_at' => $this->cancelled_at?->toDateString(),
            'notes' => $this->notes,

            'member' => $this->whenLoaded('member', fn () => $this->member ? [
                'id' => $this->member->id,
                'member_number' => $this->member->member_number,
                'name' => $this->member->display_name,
            ] : null),

            'bibliography' => $this->whenLoaded('bibliography', fn () => $this->bibliography ? [
                'id' => $this->bibliography->id,
                'title' => $this->bibliography->title,
                'slug' => $this->bibliography->slug,
                'control_number' => $this->bibliography->control_number,
            ] : null),

            'item' => $this->whenLoaded('item', fn () => $this->item ? [
                'id' => $this->item->id,
                'item_code' => $this->item->item_code,
                'barcode' => $this->item->barcode,
            ] : null),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}