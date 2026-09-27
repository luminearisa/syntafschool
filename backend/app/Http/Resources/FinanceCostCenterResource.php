<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cost center (§7) — unit pengukur pendapatan/pengeluaran/anggaran.
 */
class FinanceCostCenterResource extends JsonResource
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
            'parent_id' => $this->parent_id !== null ? (int) $this->parent_id : null,
            'manager_id' => $this->manager_id !== null ? (int) $this->manager_id : null,
            'manager_name' => $this->whenLoaded('manager', fn (): ?string => $this->manager?->name),
            'is_active' => (bool) $this->is_active,
            'description' => $this->description,
            'parent' => new FinanceCostCenterResource($this->whenLoaded('parent')),
            'children' => FinanceCostCenterResource::collection($this->whenLoaded('children')),
            'budgets_count' => $this->when(isset($this->budgets_count), fn (): int => (int) $this->budgets_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
