<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizRevisionRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enrollment_id' => $this->enrollment_id,
            'participant_id' => $this->participant_id,
            'halaqah_id' => $this->halaqah_id,
            'session_id' => $this->session_id,
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
            'page_start' => $this->page_start,
            'page_end' => $this->page_end,
            'method' => $this->method,
            'method_label' => $this->methodLabel(),
            'duration_minutes' => $this->duration_minutes,
            'score' => $this->score !== null ? (float) $this->score : null,
            'mistakes_count' => $this->mistakes_count,
            'teacher_note' => $this->teacher_note,
            'participant' => $this->whenLoaded('participant', fn () => [
                'id' => $this->participant->id,
                'name' => $this->participant->name,
            ]),
            'halaqah' => $this->whenLoaded('halaqah', fn () => [
                'id' => $this->halaqah->id,
                'name' => $this->halaqah->name,
                'code' => $this->halaqah->code,
            ]),
            'recorded_by' => $this->recorded_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    protected function methodLabel(): string
    {
        return [
            'individual' => 'Individual',
            'paired' => 'Berpasangan',
            'group' => 'Kelompok',
            'tasmi' => 'Tasmi\'',
            'teacher_listening' => 'Dibacakan kepada Guru',
        ][$this->method] ?? $this->method;
    }
}
