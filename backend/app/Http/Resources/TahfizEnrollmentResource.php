<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizEnrollmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'participant_id' => $this->participant_id,
            'program_id' => $this->program_id,
            'level_id' => $this->level_id,
            'halaqah_id' => $this->halaqah_id,
            'school_class_id' => $this->school_class_id,
            'academic_year_id' => $this->academic_year_id,
            'semester_id' => $this->semester_id,
            'status' => $this->status,
            'enrolled_at' => $this->enrolled_at?->toDateString(),
            'start_date' => $this->start_date?->toDateString(),
            'expected_end_date' => $this->expected_end_date?->toDateString(),
            'completed_at' => $this->completed_at?->toDateString(),
            'starting_juz' => $this->starting_juz,
            'target_note' => $this->target_note,
            'notes' => $this->notes,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'is_active' => $this->status === \App\Models\TahfizEnrollment::STATUS_ACTIVE,
            'is_ongoing' => in_array($this->status, \App\Models\TahfizEnrollment::ONGOING_STATUSES, true),

            'participant' => $this->whenLoaded('participant', fn () => $this->participant ? [
                'id' => $this->participant->id,
                'name' => $this->participant->name,
                'participant_type' => $this->participant->participant_type,
                'gender' => $this->participant->gender,
                'phone' => $this->participant->phone,
            ] : null),

            'program' => $this->whenLoaded('program', fn () => $this->program ? [
                'id' => $this->program->id,
                'name' => $this->program->name,
                'code' => $this->program->code,
                'target_type' => $this->program->target_type,
                'target_value' => $this->program->target_value,
            ] : null),

            'level' => $this->whenLoaded('level', fn () => $this->level ? [
                'id' => $this->level->id,
                'name' => $this->level->name,
                'juz_start' => $this->level->juz_start,
                'juz_end' => $this->level->juz_end,
            ] : null),

            'halaqah' => $this->whenLoaded('halaqah', fn () => $this->halaqah ? [
                'id' => $this->halaqah->id,
                'name' => $this->halaqah->name,
                'code' => $this->halaqah->code,
                'capacity' => $this->halaqah->capacity,
                'schedule_summary' => $this->halaqah->schedule_summary,
            ] : null),

            'school_class' => $this->whenLoaded('schoolClass', fn () => $this->schoolClass ? [
                'id' => $this->schoolClass->id,
                'name' => $this->schoolClass->name,
            ] : null),

            'approved_by' => $this->approved_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
