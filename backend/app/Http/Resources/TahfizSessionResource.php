<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'halaqah_id' => $this->halaqah_id,
            'schedule_id' => $this->schedule_id,
            'activity_type' => $this->activity_type,
            'date' => $this->date?->toDateString(),
            'start_time' => $this->start_time?->format('H:i'),
            'end_time' => $this->end_time?->format('H:i'),
            'room_id' => $this->room_id,
            'instructor_id' => $this->instructor_id,
            'topic' => $this->topic,
            'status' => $this->status,
            'notes' => $this->notes,

            'attendances_count' => (int) ($this->attendances_count ?? 0),
            'memorization_records_count' => (int) ($this->memorization_records_count ?? 0),

            'halaqah' => $this->whenLoaded('halaqah', fn () => $this->halaqah ? [
                'id' => $this->halaqah->id,
                'name' => $this->halaqah->name,
                'code' => $this->halaqah->code,
                'instructor_id' => $this->halaqah->instructor_id,
            ] : null),

            'schedule' => $this->whenLoaded('schedule', fn () => $this->schedule ? [
                'id' => $this->schedule->id,
                'activity_type' => $this->schedule->activity_type,
                'day_of_week' => $this->schedule->day_of_week,
            ] : null),

            'room' => $this->whenLoaded('room', fn () => $this->room ? [
                'id' => $this->room->id,
                'name' => $this->room->name,
            ] : null),

            'instructor' => $this->whenLoaded('instructor', fn () => $this->instructor ? [
                'id' => $this->instructor->id,
                'full_name' => $this->instructor->full_name,
            ] : null),

            'attendances' => $this->whenLoaded('attendances', fn () => $this->attendances->map(fn ($a) => [
                'id' => $a->id,
                'participant_id' => $a->participant_id,
                'status' => $a->status,
                'arrival_time' => $a->arrival_time?->format('H:i'),
            ])),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
