<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'halaqah_id' => $this->halaqah_id,
            'program_id' => $this->program_id,
            'activity_type' => $this->activity_type,
            'day_of_week' => $this->day_of_week,
            'day_label' => $this->dayLabel(),
            'start_time' => $this->start_time?->format('H:i'),
            'end_time' => $this->end_time?->format('H:i'),
            'room_id' => $this->room_id,
            'instructor_id' => $this->instructor_id,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_until' => $this->effective_until?->toDateString(),
            'status' => $this->status,

            'halaqah' => $this->whenLoaded('halaqah', fn () => $this->halaqah ? [
                'id' => $this->halaqah->id,
                'name' => $this->halaqah->name,
                'code' => $this->halaqah->code,
            ] : null),

            'program' => $this->whenLoaded('program', fn () => $this->program ? [
                'id' => $this->program->id,
                'name' => $this->program->name,
            ] : null),

            'room' => $this->whenLoaded('room', fn () => $this->room ? [
                'id' => $this->room->id,
                'name' => $this->room->name,
            ] : null),

            'instructor' => $this->whenLoaded('instructor', fn () => $this->instructor ? [
                'id' => $this->instructor->id,
                'full_name' => $this->instructor->full_name,
            ] : null),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
