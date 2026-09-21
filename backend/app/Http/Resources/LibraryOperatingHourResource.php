<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LibraryOperatingHourResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'library_id' => $this->library_id,
            'day_of_week' => $this->day_of_week,
            'day_label' => $this->dayLabel(),
            'is_open' => (bool) $this->is_open,
            'open_time' => $this->open_time?->format('H:i'),
            'close_time' => $this->close_time?->format('H:i'),
            'interval_label' => $this->is_open && $this->open_time !== null && $this->close_time !== null
                ? $this->open_time->format('H:i').' – '.$this->close_time->format('H:i')
                : 'Tutup',
        ];
    }
}