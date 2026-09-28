<?php

namespace App\Modules\LMS\Http\Resources;

use App\Modules\LMS\Models\LmsCourseSection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LmsCourseSection */
class LmsSectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'course_id' => (int) $this->course_id,
            'title' => (string) $this->title,
            'description' => $this->description,
            'summary' => $this->summary,
            'sort_order' => (int) $this->sort_order,
            'visibility' => $this->visibility?->value,
            'available_from' => $this->available_from?->toIso8601String(),
            'available_until' => $this->available_until?->toIso8601String(),
            'availability' => $this->availability,
            'completion_rule' => $this->completion_rule,
            'items' => LmsLearningItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
