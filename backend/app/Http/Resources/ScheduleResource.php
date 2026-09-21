<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_class_id' => $this->school_class_id,
            'subject_id' => $this->subject_id,
            'employee_id' => $this->employee_id,
            'room_id' => $this->room_id,
            'day_id' => $this->day_id,
            'semester_id' => $this->semester_id,
            'time_slot_id' => $this->time_slot_id,
            'start_time' => $this->shortTime($this->start_time),
            'end_time' => $this->shortTime($this->end_time),
            'note' => $this->note,
            'school_class' => $this->whenLoaded('schoolClass', fn () => $this->schoolClass ? [
                'id' => $this->schoolClass->id,
                'name' => $this->schoolClass->name,
                'code' => $this->schoolClass->code,
            ] : null),
            'subject' => $this->whenLoaded('subject', fn () => $this->subject ? [
                'id' => $this->subject->id,
                'code' => $this->subject->code,
                'name' => $this->subject->name,
            ] : null),
            'teacher' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'nip' => $this->employee->nip,
                'full_name' => $this->employee->full_name,
            ] : null),
            'room' => $this->whenLoaded('room', fn () => $this->room ? [
                'id' => $this->room->id,
                'code' => $this->room->code,
                'name' => $this->room->name,
            ] : null),
            'day' => $this->whenLoaded('day', fn () => $this->day ? [
                'id' => $this->day->id,
                'name' => $this->day->name,
            ] : null),
            'time_slot' => $this->whenLoaded('timeSlot', fn () => $this->timeSlot ? [
                'id' => $this->timeSlot->id,
                'slot_number' => $this->timeSlot->slot_number,
                'label' => $this->timeSlot->label,
                'start_time' => $this->shortTime($this->timeSlot->start_time),
                'end_time' => $this->shortTime($this->timeSlot->end_time),
                'type' => $this->timeSlot->type,
            ] : null),
            'changes' => ScheduleChangeResource::collection($this->whenLoaded('changes')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** Kolom `time` disimpan sebagai H:i:s; API memakai H:i. */
    protected function shortTime(?string $value): ?string
    {
        return $value ? substr($value, 0, 5) : null;
    }
}
