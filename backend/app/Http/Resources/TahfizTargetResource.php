<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizTargetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope,
            'scope_label' => $this->scope === 'program' ? 'Program' : 'Halaqah',
            'program_id' => $this->program_id,
            'halaqah_id' => $this->halaqah_id,
            'program' => $this->whenLoaded('program', fn () => [
                'id' => $this->program->id,
                'name' => $this->program->name,
                'code' => $this->program->code,
            ]),
            'halaqah' => $this->whenLoaded('halaqah', fn () => [
                'id' => $this->halaqah->id,
                'name' => $this->halaqah->name,
                'code' => $this->halaqah->code,
            ]),
            'surah_id' => $this->surah_id,
            'surah' => $this->whenLoaded('surah', fn () => [
                'id' => $this->surah->id,
                'name' => $this->surah->name,
            ]),
            'target_type' => $this->target_type,
            'target_type_label' => $this->targetTypeLabel(),
            'value' => (float) $this->value,
            'period' => $this->period,
            'period_label' => $this->periodLabel(),
            'is_active' => (bool) $this->is_active,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_until' => $this->effective_until?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    protected function targetTypeLabel(): string
    {
        return [
            'juz' => 'Juz',
            'surah' => 'Surah',
            'ayat' => 'Ayat',
            'page' => 'Halaman',
            'line' => 'Baris',
        ][$this->target_type] ?? $this->target_type;
    }

    protected function periodLabel(): string
    {
        return [
            'daily' => 'Harian',
            'weekly' => 'Mingguan',
            'monthly' => 'Bulanan',
            'semester' => 'Semester',
            'program' => 'Selama Program',
        ][$this->period] ?? $this->period;
    }
}
