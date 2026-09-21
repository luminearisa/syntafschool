<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizRubricResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'scope' => $this->scope,
            'description' => $this->description,
            'max_score' => $this->max_score,
            'status' => $this->status,
            'total_weight' => (float) ($this->items->sum('weight') ?? 0),

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'criterion' => $item->criterion,
                'weight' => (float) $item->weight,
                'order' => $item->order,
                'level_descriptions' => $item->level_descriptions,
            ])),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
