<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizProgramResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'participant_type' => $this->participant_type,
            'duration_value' => $this->duration_value,
            'duration_unit' => $this->duration_unit,
            'target_type' => $this->target_type,
            'target_value' => $this->target_value,
            'minimum_attendance' => $this->minimum_attendance,
            'assessment_scheme' => $this->assessment_scheme,
            'requires_placement_test' => (bool) $this->requires_placement_test,
            'self_registration' => (bool) $this->self_registration,
            'status' => $this->status,

            'levels_count' => (int) ($this->levels_count ?? 0),
            'enrollments_count' => (int) ($this->enrollments_count ?? 0),
            'halaqahs_count' => (int) ($this->halaqahs_count ?? 0),

            'levels' => $this->whenLoaded('levels', fn () => $this->levels->map(fn ($level) => [
                'id' => $level->id,
                'name' => $level->name,
                'code' => $level->code,
                'order' => $level->order,
                'juz_start' => $level->juz_start,
                'juz_end' => $level->juz_end,
            ])),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
