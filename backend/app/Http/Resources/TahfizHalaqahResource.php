<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizHalaqahResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'program_id' => $this->program_id,
            'level_id' => $this->level_id,
            'name' => $this->name,
            'code' => $this->code,
            'capacity' => $this->capacity,
            'room_id' => $this->room_id,
            'instructor_id' => $this->instructor_id,
            'assistant_id' => $this->assistant_id,
            'musyrif_id' => $this->musyrif_id,
            'schedule_summary' => $this->schedule_summary,
            'academic_year_id' => $this->academic_year_id,
            'semester_id' => $this->semester_id,
            'status' => $this->status,
            'notes' => $this->notes,

            'active_members_count' => (int) ($this->active_members_count ?? $this->activeMembersCount()),
            'has_capacity' => $this->hasCapacity(),

            'program' => $this->whenLoaded('program', fn () => $this->program ? [
                'id' => $this->program->id,
                'name' => $this->program->name,
                'code' => $this->program->code,
            ] : null),

            'level' => $this->whenLoaded('level', fn () => $this->level ? [
                'id' => $this->level->id,
                'name' => $this->level->name,
                'juz_start' => $this->level->juz_start,
                'juz_end' => $this->level->juz_end,
            ] : null),

            'room' => $this->whenLoaded('room', fn () => $this->room ? [
                'id' => $this->room->id,
                'name' => $this->room->name,
            ] : null),

            'instructor' => $this->employeeSummary($this->whenLoaded('instructor') ? $this->instructor : null),
            'assistant' => $this->employeeSummary($this->whenLoaded('assistant') ? $this->assistant : null),
            'musyrif' => $this->employeeSummary($this->whenLoaded('musyrif') ? $this->musyrif : null),

            'members' => $this->whenLoaded('activeMembers', fn () => $this->activeMembers->map(fn ($m) => [
                'id' => $m->id,
                'participant_id' => $m->participant_id,
                'enrollment_id' => $m->enrollment_id,
                'joined_at' => $m->joined_at?->toDateString(),
                'participant' => $m->relationLoaded('participant') && $m->participant ? [
                    'id' => $m->participant->id,
                    'name' => $m->participant->name,
                    'participant_type' => $m->participant->participant_type,
                ] : null,
            ])),

            'schedules_count' => (int) ($this->schedules_count ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function employeeSummary($employee): ?array
    {
        return $employee ? [
            'id' => $employee->id,
            'full_name' => $employee->full_name,
            'nip' => $employee->nip,
        ] : null;
    }
}
