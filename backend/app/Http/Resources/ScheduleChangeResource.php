<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleChangeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'schedule_id' => $this->schedule_id,
            'date' => $this->date?->format('Y-m-d'),
            'type' => $this->type,
            'substitute_employee_id' => $this->substitute_employee_id,
            'new_room_id' => $this->new_room_id,
            'new_day_id' => $this->new_day_id,
            'new_start_time' => $this->shortTime($this->new_start_time),
            'new_end_time' => $this->shortTime($this->new_end_time),
            'reason' => $this->reason,
            'substitute_teacher' => $this->whenLoaded('substituteEmployee', fn () => $this->substituteEmployee ? [
                'id' => $this->substituteEmployee->id,
                'full_name' => $this->substituteEmployee->full_name,
            ] : null),
            'new_room' => $this->whenLoaded('newRoom', fn () => $this->newRoom ? [
                'id' => $this->newRoom->id,
                'name' => $this->newRoom->name,
            ] : null),
            'new_day' => $this->whenLoaded('newDay', fn () => $this->newDay ? [
                'id' => $this->newDay->id,
                'name' => $this->newDay->name,
            ] : null),
            'created_by_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'schedule' => $this->whenLoaded('schedule', fn () => $this->schedule ? [
                'id' => $this->schedule->id,
                'start_time' => $this->shortTime($this->schedule->start_time),
                'end_time' => $this->shortTime($this->schedule->end_time),
                'school_class' => $this->schedule->relationLoaded('schoolClass') && $this->schedule->schoolClass ? [
                    'id' => $this->schedule->schoolClass->id,
                    'name' => $this->schedule->schoolClass->name,
                ] : null,
                'subject' => $this->schedule->relationLoaded('subject') && $this->schedule->subject ? [
                    'id' => $this->schedule->subject->id,
                    'name' => $this->schedule->subject->name,
                ] : null,
                'teacher' => $this->schedule->relationLoaded('employee') && $this->schedule->employee ? [
                    'id' => $this->schedule->employee->id,
                    'full_name' => $this->schedule->employee->full_name,
                ] : null,
                'room' => $this->schedule->relationLoaded('room') && $this->schedule->room ? [
                    'id' => $this->schedule->room->id,
                    'name' => $this->schedule->room->name,
                ] : null,
                'day' => $this->schedule->relationLoaded('day') && $this->schedule->day ? [
                    'id' => $this->schedule->day->id,
                    'name' => $this->schedule->day->name,
                ] : null,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    protected function shortTime(?string $value): ?string
    {
        return $value ? substr($value, 0, 5) : null;
    }
}
