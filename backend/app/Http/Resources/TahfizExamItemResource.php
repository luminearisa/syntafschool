<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizExamItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam_participant_id' => $this->exam_participant_id,
            'item_type' => $this->item_type,
            'surah_id' => $this->surah_id,
            'surah' => $this->whenLoaded('surah', fn () => [
                'id' => $this->surah->id,
                'number' => $this->surah->number,
                'name' => $this->surah->name,
                'arabic_name' => $this->surah->arabic_name,
                'ayah_count' => $this->surah->ayah_count,
            ]),
            'ayah_start' => $this->ayah_start,
            'expected_ayah_end' => $this->expected_ayah_end,
            'actual_ayah_end' => $this->actual_ayah_end,
            // Benar bila peserta melanjutkan sampai ayat yang diharapkan.
            'is_correct' => $this->actual_ayah_end !== null
                && $this->expected_ayah_end !== null
                && (int) $this->actual_ayah_end >= (int) $this->expected_ayah_end,
            'score' => $this->score !== null ? (float) $this->score : null,
            'errors' => $this->errors,
            'note' => $this->note,
        ];
    }
}
