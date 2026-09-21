<?php

namespace App\Http\Resources;

use App\Services\Ppdb\PpdbPeriodService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pertanyaan tambahan beserta pilihan jawabannya.
 */
class PpdbQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period_id' => $this->period_id,
            'program_id' => $this->program_id,
            'wave_id' => $this->wave_id,
            'scope' => PpdbPeriodService::scopeLabel($this->program_id, $this->wave_id),
            'code' => $this->code,
            'question' => $this->question,
            'description' => $this->description,
            'type' => $this->type,
            'required' => $this->required,
            'active' => $this->active,
            'order' => $this->order,

            'options' => $this->whenLoaded('options', fn () => $this->options->map(fn ($option) => [
                'id' => $option->id,
                'label' => $option->label,
                'value' => $option->value ?? $option->label,
                'order' => $option->order,
            ])->values()->all()),

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
