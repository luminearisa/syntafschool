<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EffectiveDayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'semester_id' => $this->semester_id,
            'education_level_id' => $this->education_level_id,
            'date' => $this->date?->toDateString(),
            'is_school_day' => (bool) $this->is_school_day,
            'note' => $this->note,
            'semester' => $this->whenLoaded('semester', fn () => $this->semester ? [
                'id' => $this->semester->id,
                'name' => $this->semester->name,
            ] : null),
            'education_level' => $this->whenLoaded('educationLevel', fn () => $this->educationLevel ? [
                'id' => $this->educationLevel->id,
                'name' => $this->educationLevel->name,
                'jenjang' => $this->educationLevel->jenjang,
                'level_number' => $this->educationLevel->level_number,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
