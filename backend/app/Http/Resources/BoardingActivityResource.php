<?php

namespace App\Http\Resources;

use App\Models\BoardingActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kegiatan asrama (§16) — absensi selalu melekat pada satu kegiatan.
 */
class BoardingActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category,
            'category_label' => $this->categoryLabel(),
            'default_attendance_type_id' => $this->default_attendance_type_id === null
                ? null
                : (int) $this->default_attendance_type_id,
            'default_attendance_type' => $this->whenLoaded(
                'defaultAttendanceType',
                fn () => $this->defaultAttendanceType === null
                    ? null
                    : BoardingAttendanceTypeResource::make($this->defaultAttendanceType)
            ),
            'late_after_minutes' => $this->late_after_minutes === null ? null : (int) $this->late_after_minutes,
            'is_required' => (bool) $this->is_required,
            'is_active' => (bool) $this->is_active,
            'description' => $this->description,
            'attendance_types' => BoardingAttendanceTypeResource::collection($this->whenLoaded('attendanceTypes')),
            'schedules_count' => $this->when(isset($this->schedules_count), fn (): int => (int) $this->schedules_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
