<?php

namespace App\Modules\Asset\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FacilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'facility_code' => $this->facility_code,
            'name' => $this->name,
            'type' => $this->whenLoaded('type', fn () => $this->type?->only(['id', 'code', 'name', 'category'])),
            'location' => $this->whenLoaded('location', fn () => $this->location?->only(['id', 'code', 'name', 'path'])),
            'campus_id' => $this->campus_id,
            'capacity' => [
                'total' => $this->capacity_total,
                'normal' => $this->capacity_normal,
                'exam' => $this->capacity_exam,
                'event' => $this->capacity_event,
            ],
            'status' => $this->status?->value ?? (string) $this->status,
            'status_label' => method_exists($this->status, 'label') ? $this->status->label() : null,
            'availability' => $this->availability,
            'condition' => $this->condition?->value ?? (string) $this->condition,
            'safety_status' => $this->safety_status,
            'maintenance_status' => $this->maintenance_status,
            'manager_user_id' => $this->manager_user_id,
            'responsible_unit_id' => $this->responsible_unit_id,
            'booking' => [
                'min_minutes' => $this->min_booking_minutes,
                'max_minutes' => $this->max_booking_minutes,
                'advance_days' => $this->advance_booking_days,
                'requires_approval' => $this->requires_approval,
            ],
            'operational_hours' => $this->whenLoaded('operatingHours', fn () => $this->operatingHours->map(fn ($hour): array => [
                'day_of_week' => (int) $hour->day_of_week,
                'open_time' => $hour->open_time,
                'close_time' => $hour->close_time,
                'is_closed' => (bool) $hour->is_closed,
            ])->all()),
            'utilization_rate' => (string) $this->utilization_rate,
            'booking_count' => (int) $this->booking_count,
            'description' => $this->description,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
