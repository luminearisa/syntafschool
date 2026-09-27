<?php

namespace App\Http\Resources;

use App\Models\BoardingViolationType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Master jenis pelanggaran asrama (§35). Poin hidup di sini; catatan
 * pelanggaran menyalinnya sebagai snapshot saat kejadian (§36).
 */
class BoardingViolationTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'category_label' => $this->categoryLabel(),
            'severity' => $this->severity,
            'severity_label' => $this->severityLabel(),
            'point' => (int) $this->point,
            'is_active' => (bool) $this->is_active,
            'violations_count' => $this->whenCounted('violations'),
            'options' => [
                'categories' => BoardingViolationType::CATEGORY_LABELS,
                'severities' => BoardingViolationType::SEVERITY_LABELS,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
