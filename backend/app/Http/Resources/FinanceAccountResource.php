<?php

namespace App\Http\Resources;

use App\Models\FinanceAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Akun COA (§5, §6). `type_label` & `normal_balance` diturunkan dari tipe akun;
 * `parent` dan `children` hanya muncul bila controller memuatnya (tree/list).
 */
class FinanceAccountResource extends JsonResource
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
            'type' => $this->type,
            'type_label' => FinanceAccount::TYPE_LABELS[$this->type] ?? $this->type,
            'normal_balance' => $this->normal_balance,
            'parent_id' => $this->parent_id !== null ? (int) $this->parent_id : null,
            'is_system' => (bool) $this->is_system,
            'is_active' => (bool) $this->is_active,
            'is_leaf' => $this->when(isset($this->is_leaf), fn (): bool => (bool) $this->is_leaf),
            'description' => $this->description,
            'parent' => new FinanceAccountResource($this->whenLoaded('parent')),
            'children' => FinanceAccountResource::collection($this->whenLoaded('children')),
            'children_count' => $this->when(isset($this->children_count), fn (): int => (int) $this->children_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
