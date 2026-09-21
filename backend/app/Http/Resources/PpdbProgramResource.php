<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Penawaran program pada satu periode PPDB.
 */
class PpdbProgramResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period_id' => $this->period_id,
            'code' => $this->code,
            'name' => $this->name,
            'quota' => $this->quota,
            'description' => $this->description,
            'status' => $this->status,
            'sort_order' => $this->sort_order,

            // Tautan ke master yang sudah ada — tidak pernah diduplikasi.
            'program_id' => $this->program_id,
            'program' => $this->whenLoaded('program', fn () => $this->program ? [
                'id' => $this->program->id,
                'code' => $this->program->code,
                'name' => $this->program->name,
            ] : null),
            'education_level_id' => $this->education_level_id,
            'education_level' => $this->whenLoaded('educationLevel', fn () => $this->educationLevel ? [
                'id' => $this->educationLevel->id,
                'name' => $this->educationLevel->name,
                'jenjang' => $this->educationLevel->jenjang,
            ] : null),

            'registrations_count' => $this->whenCounted('registrations'),
            'period' => $this->whenLoaded('period', fn () => $this->period ? [
                'id' => $this->period->id,
                'code' => $this->period->code,
                'name' => $this->period->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
