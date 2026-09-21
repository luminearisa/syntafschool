<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizParticipantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'participant_type' => $this->participant_type,
            'student_id' => $this->student_id,
            'employee_id' => $this->employee_id,
            'guardian_id' => $this->guardian_id,
            'name' => $this->name,
            'gender' => $this->gender,
            'birth_date' => $this->birth_date?->toDateString(),
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'photo_path' => $this->photo_path,
            'notes' => $this->notes,
            'status' => $this->status,

            'enrollments_count' => (int) ($this->enrollments_count ?? 0),
            'active_enrollments_count' => (int) ($this->active_enrollments_count ?? 0),
            'memorization_records_count' => (int) ($this->memorization_records_count ?? 0),

            'profile' => $this->whenLoaded('profile', fn () => $this->profile ? [
                'experience_level' => $this->profile->experience_level,
                'last_memorized' => $this->profile->last_memorized,
                'reading_ability' => $this->profile->reading_ability,
                'memorization_target' => $this->profile->memorization_target,
                'schedule_preference' => $this->profile->schedule_preference,
                'education_background' => $this->profile->education_background,
                'occupation' => $this->profile->occupation,
                'emergency_contact' => $this->profile->emergency_contact,
            ] : null),

            'student' => $this->whenLoaded('student', fn () => $this->student ? [
                'id' => $this->student->id,
                'nis' => $this->student->nis,
                'full_name' => $this->student->full_name,
            ] : null),

            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'nip' => $this->employee->nip,
                'full_name' => $this->employee->full_name,
                'position' => $this->employee->position,
            ] : null),

            'guardian' => $this->whenLoaded('guardian', fn () => $this->guardian ? [
                'id' => $this->guardian->id,
                'full_name' => $this->guardian->full_name,
            ] : null),

            'enrollments' => $this->whenLoaded('enrollments', fn () => $this->enrollments->map(fn ($e) => [
                'id' => $e->id,
                'code' => $e->code,
                'program_id' => $e->program_id,
                'status' => $e->status,
                'level_id' => $e->level_id,
                'halaqah_id' => $e->halaqah_id,
            ])),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
