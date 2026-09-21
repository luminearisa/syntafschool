<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademicEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'semester_id' => $this->semester_id,
            'academic_year_id' => $this->academic_year_id,
            'event_type_id' => $this->event_type_id,
            'title' => $this->title,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'description' => $this->description,
            'location' => $this->location,
            'is_holiday' => (bool) $this->is_holiday,
            'is_active' => (bool) $this->is_active,
            'semester' => $this->whenLoaded('semester', fn () => $this->semester ? [
                'id' => $this->semester->id,
                'name' => $this->semester->name,
                'academic_year_id' => $this->semester->academic_year_id,
            ] : null),
            'event_type' => $this->whenLoaded('eventType', fn () => $this->eventType ? [
                'id' => $this->eventType->id,
                'code' => $this->eventType->code,
                'name' => $this->eventType->name,
                'color' => $this->eventType->color,
                'is_holiday' => (bool) $this->eventType->is_holiday,
            ] : null),
            'academic_year' => $this->whenLoaded('academicYear', fn () => $this->academicYear ? [
                'id' => $this->academicYear->id,
                'name' => $this->academicYear->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
