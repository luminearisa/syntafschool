<?php

namespace App\Http\Resources;

use App\Models\BoardingAttendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu catatan absensi (§17, §24).
 *
 * Jejak koreksi (`revision`, `revised_by`, `revised_at`) ikut dikirim: layar
 * frontend menampilkannya sebagai badge "sudah dikoreksi N kali" tanpa perlu
 * membuka log audit.
 */
class BoardingAttendanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'student_id' => (int) $this->student_id,
            'student' => $this->when(
                $this->relationLoaded('student') && $this->student !== null,
                fn (): array => [
                    'id' => (int) $this->student->id,
                    'full_name' => $this->student->full_name,
                    'nis' => $this->student->nis,
                ]
            ),
            'activity_id' => (int) $this->activity_id,
            'activity' => $this->when(
                $this->relationLoaded('activity') && $this->activity !== null,
                fn (): array => [
                    'id' => (int) $this->activity->id,
                    'code' => $this->activity->code,
                    'name' => $this->activity->name,
                    'category' => $this->activity->category,
                ]
            ),
            'schedule_id' => $this->schedule_id === null ? null : (int) $this->schedule_id,
            'attendance_type_id' => (int) $this->attendance_type_id,
            'attendance_type' => $this->whenLoaded(
                'attendanceType',
                fn (): ?array => $this->attendanceType === null
                    ? null
                    : [
                        'id' => (int) $this->attendanceType->id,
                        'code' => $this->attendanceType->code,
                        'name' => $this->attendanceType->name,
                        'category' => $this->attendanceType->category,
                        'color' => $this->attendanceType->color,
                        'is_present' => (bool) $this->attendanceType->is_present,
                    ]
            ),
            'date' => $this->date?->toDateString(),
            'session' => $this->session,
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'is_late' => (bool) $this->is_late,
            'late_minutes' => $this->late_minutes === null ? null : (int) $this->late_minutes,
            'source' => $this->source,
            'source_label' => BoardingAttendance::SOURCE_LABELS[$this->source] ?? $this->source,
            'device' => $this->device,
            'note' => $this->note,
            'recorded_by_id' => $this->recorded_by === null ? null : (int) $this->recorded_by,
            'recorded_by' => $this->when(
                $this->relationLoaded('recordedBy'),
                fn (): ?array => $this->recordedBy === null ? null : ['id' => (int) $this->recordedBy->id, 'name' => $this->recordedBy->name]
            ),
            'revision' => (int) $this->revision,
            'revised_at' => $this->revised_at?->toIso8601String(),
            'revised_by' => $this->when(
                $this->relationLoaded('revisedBy'),
                fn (): ?array => $this->revisedBy === null ? null : ['id' => (int) $this->revisedBy->id, 'name' => $this->revisedBy->name]
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
