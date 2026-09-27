<?php

namespace App\Http\Resources;

use App\Models\BoardingBuilding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Gedung di dalam satu asrama (§5), selalu menampilkan induk asramanya
 * secara minimal agar breadcrumb terbaca tanpa memanggil resource penuh.
 */
class BoardingBuildingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
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
            'code' => $this->code,
            'name' => $this->name,
            'floor_count' => (int) $this->floor_count,
            'floors_count' => $this->when(isset($this->floors_count), fn (): int => (int) $this->floors_count),
            'current_occupancy' => $this->when(isset($this->current_occupancy), fn (): int => (int) $this->current_occupancy),
            'description' => $this->description,
            'status' => $this->status,
            'status_label' => BoardingBuilding::STATUS_LABELS[$this->status] ?? $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
