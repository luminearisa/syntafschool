<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurriculumStructureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'curriculum_id' => $this->curriculum_id,
            'education_level_id' => $this->education_level_id,
            'academic_year_id' => $this->academic_year_id,
            'version' => (int) $this->version,
            'name' => $this->name,
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'items_count' => $this->whenCounted('items'),
            'total_weekly_hours' => $this->whenLoaded(
                'items',
                fn () => (int) $this->items->sum('weekly_hours'),
            ),
            'items' => CurriculumStructureItemResource::collection($this->whenLoaded('items')),
            'curriculum' => $this->whenLoaded('curriculum', fn () => [
                'id' => $this->curriculum->id,
                'code' => $this->curriculum->code,
                'name' => $this->curriculum->name,
            ]),
            'education_level' => $this->whenLoaded('educationLevel', fn () => [
                'id' => $this->educationLevel->id,
                'name' => $this->educationLevel->name,
                'jenjang' => $this->educationLevel->jenjang,
                'level_number' => $this->educationLevel->level_number,
            ]),
            'academic_year' => $this->whenLoaded('academicYear', fn () => [
                'id' => $this->academicYear->id,
                'name' => $this->academicYear->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
