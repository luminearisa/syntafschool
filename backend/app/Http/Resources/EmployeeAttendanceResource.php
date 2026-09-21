<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeAttendanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'date' => $this->date?->toDateString(),
            'check_in' => $this->shortTime($this->check_in),
            'check_out' => $this->shortTime($this->check_out),
            'attendance_status_id' => $this->attendance_status_id,
            'status_code' => $this->attendanceStatus?->code,
            'status_name' => $this->attendanceStatus?->name,
            'leave_type_id' => $this->leave_type_id,
            'notes' => $this->notes,
            'recorded_by' => $this->recorded_by,

            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'nip' => $this->employee->nip,
                'full_name' => $this->employee->full_name,
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
