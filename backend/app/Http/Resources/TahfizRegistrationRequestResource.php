<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizRegistrationRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'gender' => $this->gender,
            'birth_date' => $this->birth_date?->toDateString(),
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'program_id' => $this->program_id,
            'experience_level' => $this->experience_level,
            'last_memorized' => $this->last_memorized,
            'reading_ability' => $this->reading_ability,
            'memorization_target' => $this->memorization_target,
            'schedule_preference' => $this->schedule_preference,
            'status' => $this->status,
            'participant_id' => $this->participant_id,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),

            'program' => $this->whenLoaded('program', fn () => $this->program ? [
                'id' => $this->program->id,
                'name' => $this->program->name,
                'code' => $this->program->code,
            ] : null),

            'participant' => $this->whenLoaded('participant', fn () => $this->participant ? [
                'id' => $this->participant->id,
                'name' => $this->participant->name,
            ] : null),

            'reviewer' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy ? [
                'id' => $this->reviewedBy->id,
                'name' => $this->reviewedBy->name,
            ] : null),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
