<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lantai pada satu gedung (§5). `display_name` mengikuti konvensi model:
 * memakai `name` bila diisi, jika tidak "Lantai N".
 */
class BoardingFloorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'building_id' => (int) $this->building_id,
            'building' => $this->when(
                $this->relationLoaded('building') && $this->building !== null,
                fn (): array => [
                    'id' => (int) $this->building->id,
                    'code' => $this->building->code,
                    'name' => $this->building->name,
                    'dormitory_id' => (int) $this->building->dormitory_id,
                ]
            ),
            'floor_number' => (int) $this->floor_number,
            'name' => $this->name,
            'display_name' => $this->displayName(),
            'description' => $this->description,
            'rooms_count' => $this->when(isset($this->rooms_count), fn (): int => (int) $this->rooms_count),
            'current_occupancy' => $this->when(isset($this->current_occupancy), fn (): int => (int) $this->current_occupancy),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
