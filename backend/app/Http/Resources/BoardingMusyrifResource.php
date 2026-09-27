<?php

namespace App\Http\Resources;

use App\Models\BoardingMusyrif;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Penugasan pengasuh pada satu area (§8).
 *
 * `area_label` merangkai asrama · kamar untuk teks tampilan; nama gedung dan
 * kamar tetap tersedia terpisah agar frontend bisa membuat breadcrumb.
 */
class BoardingMusyrifResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'employee_id' => (int) $this->employee_id,
            'employee' => $this->when(
                $this->relationLoaded('employee') && $this->employee !== null,
                fn (): array => [
                    'id' => (int) $this->employee->id,
                    'full_name' => $this->employee->full_name,
                    'nip' => $this->employee->nip,
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
            'building_id' => $this->building_id === null ? null : (int) $this->building_id,
            'building' => $this->when(
                $this->relationLoaded('building') && $this->building !== null,
                fn (): array => [
                    'id' => (int) $this->building->id,
                    'code' => $this->building->code,
                    'name' => $this->building->name,
                ]
            ),
            'room_id' => $this->room_id === null ? null : (int) $this->room_id,
            'room' => $this->when(
                $this->relationLoaded('room') && $this->room !== null,
                fn (): array => [
                    'id' => (int) $this->room->id,
                    'code' => $this->room->code,
                    'name' => $this->room->name,
                ]
            ),
            'area_label' => $this->areaLabel(),
            'position' => $this->position,
            'position_label' => $this->positionLabel(),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'status' => $this->status,
            'status_label' => BoardingMusyrif::STATUS_LABELS[$this->status] ?? $this->status,
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
