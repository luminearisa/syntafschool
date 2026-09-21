<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimeSlotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'day_id' => $this->day_id,
            'slot_number' => $this->slot_number,
            'label' => $this->label,
            'start_time' => $this->shortTime($this->start_time),
            'end_time' => $this->shortTime($this->end_time),
            'type' => $this->type,
            'is_active' => (bool) $this->is_active,
            'day' => $this->whenLoaded('day', fn () => $this->day ? [
                'id' => $this->day->id,
                'name' => $this->day->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** Kolom `time` disimpan sebagai H:i:s; API memakai H:i agar cocok dengan <input type="time">. */
    protected function shortTime(?string $value): ?string
    {
        return $value ? substr($value, 0, 5) : null;
    }
}
