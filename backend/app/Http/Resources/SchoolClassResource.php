<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SchoolClassResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'academic_year_id' => $this->academic_year_id,
            'education_level_id' => $this->education_level_id,
            'room_id' => $this->room_id,
            'name' => $this->name,
            'code' => $this->code,
            'homeroom_teacher_id' => $this->homeroom_teacher_id,
            'capacity' => $this->capacity,
            'is_active' => (bool) $this->is_active,
            'students_count' => $this->whenCounted('classStudents'),
            'class_subjects_count' => $this->whenCounted('classSubjects'),
            'academic_year' => new AcademicYearResource($this->whenLoaded('academicYear')),
            'education_level' => $this->whenLoaded('educationLevel'),
            'room' => $this->whenLoaded('room'),
            'homeroom_teacher' => $this->whenLoaded('homeroomTeacher'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
