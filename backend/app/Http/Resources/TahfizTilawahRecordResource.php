<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizTilawahRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enrollment_id' => $this->enrollment_id,
            'participant_id' => $this->participant_id,
            'date' => $this->date?->toDateString(),
            'surah_id' => $this->surah_id,
            'surah' => $this->whenLoaded('surah', fn () => [
                'id' => $this->surah->id,
                'number' => $this->surah->number,
                'name' => $this->surah->name,
                'arabic_name' => $this->surah->arabic_name,
            ]),
            'ayah_start' => $this->ayah_start,
            'ayah_end' => $this->ayah_end,
            'juz' => $this->juz,
            'page_count' => $this->page_count,
            'duration_minutes' => $this->duration_minutes,
            'quality' => $this->quality,
            'note' => $this->note,
            'participant' => $this->whenLoaded('participant', fn () => [
                'id' => $this->participant->id,
                'name' => $this->participant->name,
            ]),
            'recorded_by' => $this->recorded_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
