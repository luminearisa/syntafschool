<?php

namespace App\Http\Resources;

use App\Models\BoardingRoom;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kamar asrama — satuan penempatan harian (§5, §7).
 *
 * `current_occupancy` dan `available_beds` ditambahkan hanya bila controller
 * sudah memuatnya (`withCount`), supaya daftar kamar tidak memicu satu query
 * per baris saat frontend hanya butuh identitas kamar.
 */
class BoardingRoomResource extends JsonResource
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
            'floor_id' => (int) $this->floor_id,
            'floor' => $this->when(
                $this->relationLoaded('floor') && $this->floor !== null,
                fn (): array => [
                    'id' => (int) $this->floor->id,
                    'floor_number' => (int) $this->floor->floor_number,
                    'name' => $this->floor->name,
                    'display_name' => $this->floor->displayName(),
                    'building_id' => (int) $this->floor->building_id,
                ]
            ),
            'dormitory_id' => (int) $this->dormitory_id,
            'dormitory' => $this->when(
                $this->relationLoaded('dormitory') && $this->dormitory !== null,
                fn (): array => [
                    'id' => (int) $this->dormitory->id,
                    'code' => $this->dormitory->code,
                    'name' => $this->dormitory->name,
                    'gender' => $this->dormitory->gender,
                ]
            ),
            'capacity' => (int) $this->capacity,
            'current_occupancy' => $this->when(isset($this->current_occupancy), fn (): int => (int) $this->current_occupancy),
            'available_beds' => $this->when(isset($this->available_beds), fn (): int => (int) $this->available_beds),
            'beds_count' => $this->when(isset($this->beds_count), fn (): int => (int) $this->beds_count),
            'remaining_capacity' => $this->when(
                isset($this->current_occupancy),
                fn (): int => max(0, (int) $this->capacity - (int) $this->current_occupancy)
            ),
            'gender' => $this->gender,
            'gender_label' => $this->genderLabel(),
            'room_type' => $this->room_type,
            'room_type_label' => BoardingRoom::ROOM_TYPE_LABELS[$this->room_type] ?? $this->room_type,
            'status' => $this->status,
            'status_label' => BoardingRoom::STATUS_LABELS[$this->status] ?? $this->status,
            'is_isolation' => $this->isIsolation(),
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
