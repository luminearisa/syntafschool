<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizInstructorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'role' => $this->role,
            'specialization' => $this->specialization,
            'certificate' => $this->certificate,
            'notes' => $this->notes,
            'is_active' => (bool) $this->is_active,

            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
                'nip' => $this->employee->nip,
                'position' => $this->employee->position,
                'phone' => $this->employee->phone,
                'photo_path' => $this->employee->photo_path,
            ] : null),

            'halaqahs_count' => (int) ($this->halaqahs_count ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
