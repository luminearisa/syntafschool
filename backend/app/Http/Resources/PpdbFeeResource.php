<?php

namespace App\Http\Resources;

use App\Services\Ppdb\PpdbPeriodService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu pos biaya PPDB.
 */
class PpdbFeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period_id' => $this->period_id,
            'program_id' => $this->program_id,
            'wave_id' => $this->wave_id,
            'scope' => PpdbPeriodService::scopeLabel($this->program_id, $this->wave_id),
            'type' => $this->type,
            'name' => $this->name,
            'amount' => (float) $this->amount,
            'required' => $this->required,
            'payment_deadline' => $this->payment_deadline?->toDateString(),
            'active' => $this->active,
            'program' => $this->whenLoaded('program', fn () => $this->program ? [
                'id' => $this->program->id,
                'code' => $this->program->code,
                'name' => $this->program->name,
            ] : null),
            'wave' => $this->whenLoaded('wave', fn () => $this->wave ? [
                'id' => $this->wave->id,
                'code' => $this->wave->code,
                'name' => $this->wave->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
