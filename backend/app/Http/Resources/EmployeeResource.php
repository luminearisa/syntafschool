<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'nip' => $this->nip,
            'full_name' => $this->full_name,
            'gender_id' => $this->gender_id,
            'religion_id' => $this->religion_id,
            'birth_place' => $this->birth_place,
            'birth_date' => $this->birth_date?->toDateString(),
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'photo_path' => $this->photo_path,
            'position' => $this->position,
            'employee_type' => $this->employee_type,
            'employment_status' => $this->employment_status,
            'join_date' => $this->join_date?->toDateString(),
            'is_teacher' => $this->is_teacher,
            'is_active' => $this->is_active,

            'gender' => $this->whenLoaded('gender', fn () => $this->gender ? [
                'id' => $this->gender->id,
                'name' => $this->gender->name,
            ] : null),
            'religion' => $this->whenLoaded('religion', fn () => $this->religion ? [
                'id' => $this->religion->id,
                'name' => $this->religion->name,
            ] : null),
            'school' => $this->whenLoaded('school', fn () => $this->school ? [
                'id' => $this->school->id,
                'name' => $this->school->name,
            ] : null),

            'homeroom_classes_count' => $this->whenCounted('homeroomClasses'),
            'class_subjects_count' => $this->whenCounted('classSubjects'),
            'has_account' => $this->whenLoaded('user', fn () => $this->user !== null),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
