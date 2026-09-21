<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LibraryLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'library_id' => $this->library_id,
            'parent_id' => $this->parent_id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'description' => $this->description,
            'status' => $this->status,
            'is_active' => $this->status === \App\Models\LibraryLocation::STATUS_ACTIVE,

            'children_count' => (int) ($this->children_count ?? 0),

            'parent' => $this->whenLoaded('parent', fn () => $this->parent ? [
                'id' => $this->parent->id,
                'name' => $this->parent->name,
                'code' => $this->parent->code,
            ] : null),

            // Dipakai halaman Lokasi untuk membentuk pohon.
            'children' => LibraryLocationResource::collection($this->whenLoaded('children')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}