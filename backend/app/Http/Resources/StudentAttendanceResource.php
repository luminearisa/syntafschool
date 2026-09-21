<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentAttendanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'class_id' => $this->school_class_id,
            'semester_id' => $this->semester_id,
            'schedule_id' => $this->schedule_id,
            'subject_id' => $this->subject_id,
            'date' => $this->date?->toDateString(),
            'attendance_status_id' => $this->attendance_status_id,
            'status_code' => $this->attendanceStatus?->code,
            'status_name' => $this->attendanceStatus?->name,
            'leave_type_id' => $this->leave_type_id,
            'time_in' => $this->shortTime($this->time_in),
            'notes' => $this->notes,
            'recorded_by' => $this->recorded_by,
            'verified_by' => $this->verified_by,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'status' => $this->status,

            'student' => $this->whenLoaded('student', fn () => $this->student ? [
                'id' => $this->student->id,
                'nis' => $this->student->nis,
                'full_name' => $this->student->full_name,
            ] : null),
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
            'semester' => $this->whenLoaded('semester', fn () => $this->semester ? [
                'id' => $this->semester->id,
                'name' => $this->semester->name,
            ] : null),
            'leave_type' => $this->whenLoaded('leaveType', fn () => $this->leaveType ? [
                'id' => $this->leaveType->id,
                'name' => $this->leaveType->name,
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
