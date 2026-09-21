<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgramResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'education_level_id' => $this->education_level_id,
            'description' => $this->description,
            'is_active' => (bool) $this->is_active,
            'education_level' => $this->whenLoaded('educationLevel', fn () => [
                'id' => $this->educationLevel->id,
                'name' => $this->educationLevel->name,
                'jenjang' => $this->educationLevel->jenjang,
                'level_number' => $this->educationLevel->level_number,
            ]),
            'students_count' => $this->whenCounted('students'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
