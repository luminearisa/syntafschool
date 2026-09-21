<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizTahsinRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enrollment_id' => $this->enrollment_id,
            'participant_id' => $this->participant_id,
            'halaqah_id' => $this->halaqah_id,
            'date' => $this->date?->toDateString(),
            'rubric_id' => $this->rubric_id,
            'instructor_id' => $this->instructor_id,
            'surah_id' => $this->surah_id,
            'surah' => $this->whenLoaded('surah', fn () => [
                'id' => $this->surah->id,
                'number' => $this->surah->number,
                'name' => $this->surah->name,
            ]),
            'ayah_start' => $this->ayah_start,
            'ayah_end' => $this->ayah_end,
            'overall_score' => $this->overall_score !== null ? (float) $this->overall_score : null,
            'notes' => $this->notes,
            'scores' => TahfizTahsinScoreResource::collection($this->whenLoaded('scores')),
            'rubric' => $this->whenLoaded('rubric', fn () => [
                'id' => $this->rubric->id,
                'name' => $this->rubric->name,
                'scope' => $this->rubric->scope,
                'max_score' => (float) $this->rubric->max_score,
            ]),
            'participant' => $this->whenLoaded('participant', fn () => [
                'id' => $this->participant->id,
                'name' => $this->participant->name,
            ]),
            'instructor' => $this->whenLoaded('instructor', fn () => [
                'id' => $this->instructor->id,
                'full_name' => $this->instructor->full_name,
            ]),
            'recorded_by' => $this->recorded_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
