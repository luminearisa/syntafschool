<?php

namespace App\Http\Resources;

use App\Models\BoardingPlacement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu penempatan santri — aktif maupun riwayat (§9, §11).
 *
 * "Di mana santri sekarang" selalu berarti `status = active`, bukan baris
 * terbaru, jadi label lokasi ikut disertakan (`label`) agar daftar riwayat
 * terbaca satu baris per peristiwa.
 */
class BoardingPlacementResource extends JsonResource
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
                    'gender_id' => $this->student->gender_id === null ? null : (int) $this->student->gender_id,
                ]
            ),
            'dormitory_id' => (int) $this->dormitory_id,
            'dormitory' => $this->when(
                $this->relationLoaded('dormitory') && $this->dormitory !== null,
                fn (): array => [
                    'id' => (int) $this->dormitory->id,
                    'code' => $this->dormitory->code,
                    'name' => $this->dormitory->name,
                ]
            ),
            'building_id' => $this->building_id === null ? null : (int) $this->building_id,
            'building' => $this->when(
                $this->relationLoaded('building') && $this->building !== null,
                fn (): array => [
                    'id' => (int) $this->building->id,
                    'code' => $this->building->code,
                    'name' => $this->building->name,
                ]
            ),
            'floor_id' => $this->floor_id === null ? null : (int) $this->floor_id,
            'floor' => $this->when(
                $this->relationLoaded('floor') && $this->floor !== null,
                fn (): array => [
                    'id' => (int) $this->floor->id,
                    'floor_number' => (int) $this->floor->floor_number,
                    'display_name' => $this->floor->displayName(),
                ]
            ),
            'room_id' => (int) $this->room_id,
            'room' => $this->when(
                $this->relationLoaded('room') && $this->room !== null,
                fn (): array => [
                    'id' => (int) $this->room->id,
                    'code' => $this->room->code,
                    'name' => $this->room->name,
                    'capacity' => (int) $this->room->capacity,
                    'gender' => $this->room->gender,
                ]
            ),
            'bed_id' => $this->bed_id === null ? null : (int) $this->bed_id,
            'bed' => $this->when(
                $this->relationLoaded('bed') && $this->bed !== null,
                fn (): array => [
                    'id' => (int) $this->bed->id,
                    'bed_number' => $this->bed->bed_number,
                    'label' => $this->bed->label,
                ]
            ),
            'label' => $this->label(),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'status' => $this->status,
            'status_label' => BoardingPlacement::STATUS_LABELS[$this->status] ?? $this->status,
            'is_active' => $this->resource->isActive(),
            'end_reason' => $this->end_reason,
            'end_reason_label' => $this->end_reason === null
                ? null
                : (BoardingPlacement::END_REASON_LABELS[$this->end_reason] ?? $this->end_reason),
            'replaced_by_placement_id' => $this->replaced_by_placement_id === null
                ? null
                : (int) $this->replaced_by_placement_id,
            'notes' => $this->notes,
            'assigned_by_id' => $this->assigned_by === null ? null : (int) $this->assigned_by,
            'assigned_by' => $this->when(
                $this->relationLoaded('assignedBy'),
                fn (): ?array => $this->assignedBy === null
                    ? null
                    : ['id' => (int) $this->assignedBy->id, 'name' => $this->assignedBy->name]
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
