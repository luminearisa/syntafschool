<?php

namespace App\Http\Resources;

use App\Models\BoardingActivitySchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Jadwal mingguan kegiatan (§19) — dasar perhitungan terlambat (§25).
 */
class BoardingActivityScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'activity_id' => (int) $this->activity_id,
            'activity' => $this->when(
                $this->relationLoaded('activity') && $this->activity !== null,
                fn (): array => [
                    'id' => (int) $this->activity->id,
                    'code' => $this->activity->code,
                    'name' => $this->activity->name,
                ]
            ),
            'dormitory_id' => $this->dormitory_id === null ? null : (int) $this->dormitory_id,
            'dormitory' => $this->when(
                $this->relationLoaded('dormitory') && $this->dormitory !== null,
                fn (): array => [
                    'id' => (int) $this->dormitory->id,
                    'code' => $this->dormitory->code,
                    'name' => $this->dormitory->name,
                ]
            ),
            'group_id' => $this->group_id === null ? null : (int) $this->group_id,
            'group' => $this->when(
                $this->relationLoaded('group') && $this->group !== null,
                fn (): array => [
                    'id' => (int) $this->group->id,
                    'code' => $this->group->code,
                    'name' => $this->group->name,
                ]
            ),
            'day_of_week' => (int) $this->day_of_week,
            'day_label' => $this->dayLabel(),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'late_cutoff' => $this->lateCutoff(),
            'effective_start' => $this->effective_start?->toDateString(),
            'effective_end' => $this->effective_end?->toDateString(),
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
