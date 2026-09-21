<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceCorrectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_attendance_id' => $this->student_attendance_id,
            'old_status_id' => $this->old_status_id,
            'new_status_id' => $this->new_status_id,
            'old_status_name' => $this->oldStatus?->name,
            'new_status_name' => $this->newStatus?->name,
            'reason' => $this->reason,
            'requested_by' => $this->requested_by,
            'approved_by' => $this->approved_by,
            'status' => $this->status,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'is_pending' => $this->isPending(),

            'attendance' => $this->whenLoaded('attendance', fn () => $this->attendance ? [
                'id' => $this->attendance->id,
                'student_id' => $this->attendance->student_id,
                'class_id' => $this->attendance->school_class_id,
                'date' => $this->attendance->date?->toDateString(),
                'student' => $this->attendance->relationLoaded('student') && $this->attendance->student ? [
                    'id' => $this->attendance->student->id,
                    'nis' => $this->attendance->student->nis,
                    'full_name' => $this->attendance->student->full_name,
                ] : null,
            ] : null),
            'requester' => $this->whenLoaded('requester', fn () => $this->requester ? [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
            ] : null),
            'approver' => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
            ] : null),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
