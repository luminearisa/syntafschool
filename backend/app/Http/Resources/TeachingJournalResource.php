<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeachingJournalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'schedule_id' => $this->schedule_id,
            'class_id' => $this->class_id,
            'subject_id' => $this->subject_id,
            'teacher_id' => $this->teacher_id,
            'semester_id' => $this->semester_id,
            'time_slot_id' => $this->time_slot_id,
            'date' => $this->date?->toDateString(),
            'topic' => $this->topic,
            'material' => $this->material,
            'activities' => $this->activities,
            'notes' => $this->notes,
            'student_present_count' => (int) $this->student_present_count,
            'realized_jp' => (int) $this->realized_jp,
            'status' => $this->status,
            'attachment_path' => $this->attachment_path,
            'created_by' => $this->created_by,
            'is_completed' => $this->isCompleted(),

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
            'teacher' => $this->whenLoaded('teacher', fn () => $this->teacher ? [
                'id' => $this->teacher->id,
                'nip' => $this->teacher->nip,
                'full_name' => $this->teacher->full_name,
            ] : null),
            'semester' => $this->whenLoaded('semester', fn () => $this->semester ? [
                'id' => $this->semester->id,
                'name' => $this->semester->name,
            ] : null),
            'time_slot' => $this->whenLoaded('timeSlot', fn () => $this->timeSlot ? [
                'id' => $this->timeSlot->id,
                'slot_number' => $this->timeSlot->slot_number,
                'label' => $this->timeSlot->label,
                'start_time' => $this->shortTime($this->timeSlot->start_time),
                'end_time' => $this->shortTime($this->timeSlot->end_time),
                'type' => $this->timeSlot->type,
            ] : null),
            'schedule' => $this->whenLoaded('schedule', fn () => $this->schedule ? [
                'id' => $this->schedule->id,
                'start_time' => $this->shortTime($this->schedule->start_time),
                'end_time' => $this->shortTime($this->schedule->end_time),
                'day_id' => $this->schedule->day_id,
            ] : null),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** Kolom `time` disimpan H:i:s; API memakai H:i. */
    protected function shortTime(?string $value): ?string
    {
        return $value ? substr($value, 0, 5) : null;
    }
}
