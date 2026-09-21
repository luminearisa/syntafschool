<?php

namespace App\Http\Resources;

use App\Models\PpdbFormField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu field hasil form builder.
 *
 * `section_label` dibaca dari `PpdbFormField::SECTION_LABELS` (satu sumber
 * kebenaran), bukan dari terjemahan frontend.
 */
class PpdbFormFieldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period_id' => $this->period_id,
            'field_key' => $this->field_key,
            'label' => $this->label,
            'type' => $this->type,
            'section' => $this->section,
            'section_label' => PpdbFormField::SECTION_LABELS[$this->section] ?? $this->section,
            'placeholder' => $this->placeholder,
            'description' => $this->description,
            'required' => $this->required,
            'active' => $this->active,
            'order' => $this->order,
            'options' => $this->options ?? [],
            'validation' => $this->validation ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
