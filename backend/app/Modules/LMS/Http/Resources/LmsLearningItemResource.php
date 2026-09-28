<?php

namespace App\Modules\LMS\Http\Resources;

use App\Modules\LMS\Models\LmsLearningItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LmsLearningItem */
class LmsLearningItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'course_id' => (int) $this->course_id,
            'section_id' => (int) $this->section_id,
            'parent_id' => $this->parent_id !== null ? (int) $this->parent_id : null,
            'title' => (string) $this->title,
            'description' => $this->description,
            'item_type' => $this->item_type?->value,
            'resource_type' => $this->resource_type?->value,
            'resource_type_label' => $this->resource_type?->label(),
            'activity_type' => $this->activity_type?->value,
            'activity_type_label' => $this->activity_type?->label(),
            'icon' => $this->resource_type?->icon() ?? $this->activity_type?->icon() ?? 'file',
            'sort_order' => (int) $this->sort_order,
            'visibility' => $this->visibility?->value,
            'is_required' => (bool) $this->is_required,
            'completion_type' => $this->completion_type?->value,
            'completion_type_label' => $this->completion_type?->label(),
            'completion_min_grade' => $this->completion_min_grade !== null ? (float) $this->completion_min_grade : null,
            'is_preview' => (bool) $this->is_preview,
            'availability' => $this->availabilityRules(),
            'estimated_minutes' => $this->estimated_minutes !== null ? (int) $this->estimated_minutes : null,
            'external_activity_id' => $this->external_activity_id !== null ? (int) $this->external_activity_id : null,
            'resource' => new LmsResourceContentResource($this->whenLoaded('resource')),
            'chapters' => $this->whenLoaded('chapters', fn () => $this->chapters->map(fn ($chapter) => [
                'id' => (int) $chapter->id,
                'title' => (string) $chapter->title,
                'content' => $chapter->content,
                'sort_order' => (int) $chapter->sort_order,
            ])->values()->all()),
            'children' => self::collection($this->whenLoaded('children')),
            'children_count' => $this->whenCounted('children'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
