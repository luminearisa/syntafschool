<?php

namespace App\Modules\LMS\Http\Resources;

use App\Modules\LMS\Models\LmsCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LmsCategory */
class LmsCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'parent_id' => $this->parent_id !== null ? (int) $this->parent_id : null,
            'name' => (string) $this->name,
            'slug' => (string) $this->slug,
            'description' => $this->description,
            'image' => $this->image,
            'sort_order' => (int) $this->sort_order,
            'status' => $this->status?->value,
            'visibility' => $this->visibility?->value,
            'depth' => (int) $this->depth,
            'courses_count' => $this->whenCounted('courses'),
            'courses' => LmsCourseResource::collection($this->whenLoaded('courses')),
            'children' => self::collection($this->whenLoaded('children')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
