<?php

namespace App\Http\Resources;

use App\Models\BoardingBed;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tempat tidur — unit terkecil penempatan (§5).
 *
 * `active_placement` hanya muncul bila relasinya dimuat; isinya sengaja
 * minimal (id placement + biodata singkat santri) karena denah kamar tidak
 * memerlukan salinan `BoardingPlacementResource` penuh per kasur.
 */
class BoardingBedResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'room_id' => (int) $this->room_id,
            'room' => $this->when(
                $this->relationLoaded('room') && $this->room !== null,
                fn (): array => [
                    'id' => (int) $this->room->id,
                    'code' => $this->room->code,
                    'name' => $this->room->name,
                ]
            ),
            'bed_number' => $this->bed_number,
            'label' => $this->label,
            'display_name' => $this->displayName(),
            'status' => $this->status,
            'status_label' => BoardingBed::STATUS_LABELS[$this->status] ?? $this->status,
            'notes' => $this->notes,
            'active_placement' => $this->whenLoaded('activePlacement', function (): ?array {
                $placement = $this->activePlacement;

                if ($placement === null) {
                    return null;
                }

                $student = $placement->relationLoaded('student') ? $placement->student : null;

                return [
                    'id' => (int) $placement->id,
                    'student_id' => (int) $placement->student_id,
                    'start_date' => $placement->start_date?->toDateString(),
                    'student' => $student === null ? null : [
                        'id' => (int) $student->id,
                        'full_name' => $student->full_name,
                        'nis' => $student->nis,
                    ],
                ];
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
