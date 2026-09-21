<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizIndividualTargetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enrollment_id' => $this->enrollment_id,
            'participant_id' => $this->participant_id,
            'period' => $this->period,
            'period_label' => [
                'daily' => 'Harian',
                'weekly' => 'Mingguan',
                'monthly' => 'Bulanan',
                'semester' => 'Semester',
                'program' => 'Selama Program',
            ][$this->period] ?? $this->period,
            'target_type' => $this->target_type,
            'surah_id' => $this->surah_id,
            'surah' => $this->whenLoaded('surah', fn () => [
                'id' => $this->surah->id,
                'name' => $this->surah->name,
            ]),
            'ayah_start' => $this->ayah_start,
            'ayah_end' => $this->ayah_end,
            'juz' => $this->juz,
            'page_start' => $this->page_start,
            'page_end' => $this->page_end,
            'value' => $this->value !== null ? (float) $this->value : null,
            'unit' => $this->unit,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_until' => $this->effective_until?->toDateString(),
            'notes' => $this->notes,
            'is_active' => (bool) $this->is_active,
            'participant' => $this->whenLoaded('participant', fn () => [
                'id' => $this->participant->id,
                'name' => $this->participant->name,
            ]),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
