<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LibraryClosureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'library_id' => $this->library_id,
            'date' => $this->date?->toDateString(),
            'start_time' => $this->start_time?->format('H:i'),
            'end_time' => $this->end_time?->format('H:i'),
            'reason' => $this->reason,
            'type' => $this->type,
            'status' => $this->status,
            'is_active' => $this->status === \App\Models\LibraryClosure::STATUS_ACTIVE,
            // Tanpa jam berarti menutup sehari penuh.
            'is_full_day' => $this->start_time === null && $this->end_time === null,
            'period_label' => $this->start_time !== null && $this->end_time !== null
                ? $this->start_time->format('H:i').' – '.$this->end_time->format('H:i')
                : 'Sepanjang hari',
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}