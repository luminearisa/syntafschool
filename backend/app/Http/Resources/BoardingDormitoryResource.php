<?php

namespace App\Http\Resources;

use App\Models\BoardingDormitory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Asrama — unit tertinggi struktur fisik boarding (§5, §6).
 *
 * Kolom agregat (`buildings_count`, `rooms_count`, `current_occupancy`) hanya
 * muncul bila controller memuatnya lewat `withCount`, sehingga daftar tetap
 * ringan dan detail tidak memaksa query tambahan.
 */
class BoardingDormitoryResource extends JsonResource
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
            'gender' => $this->gender,
            'gender_label' => $this->genderLabel(),
            'capacity' => (int) $this->capacity,
            'location' => $this->location,
            'description' => $this->description,
            'status' => $this->status,
            'status_label' => BoardingDormitory::STATUS_LABELS[$this->status] ?? $this->status,
            'is_active' => (bool) $this->is_active,
            'buildings_count' => $this->when(isset($this->buildings_count), fn (): int => (int) $this->buildings_count),
            'rooms_count' => $this->when(isset($this->rooms_count), fn (): int => (int) $this->rooms_count),
            'current_occupancy' => $this->when(isset($this->current_occupancy), fn (): int => (int) $this->current_occupancy),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
