<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizLevelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'program_id' => $this->program_id,
            'name' => $this->name,
            'code' => $this->code,
            'order' => $this->order,
            'juz_start' => $this->juz_start,
            'juz_end' => $this->juz_end,
            'description' => $this->description,
            'status' => $this->status,
            'juz_range' => $this->juz_start !== null && $this->juz_end !== null
                ? ($this->juz_start === $this->juz_end ? "Juz {$this->juz_start}" : "Juz {$this->juz_start}–{$this->juz_end}")
                : null,

            'program' => $this->whenLoaded('program', fn () => $this->program ? [
                'id' => $this->program->id,
                'name' => $this->program->name,
                'code' => $this->program->code,
            ] : null),

            'halaqahs_count' => (int) ($this->halaqahs_count ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
