<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Gelombang PPDB.
 */
class PpdbWaveResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period_id' => $this->period_id,
            'code' => $this->code,
            'name' => $this->name,
            'registration_start' => $this->registration_start?->toDateString(),
            'registration_end' => $this->registration_end?->toDateString(),
            'quota' => $this->quota,
            'priority' => $this->priority,
            'status' => $this->status,
            'is_open_today' => $this->isOpenOn(),
            'description' => $this->description,
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
