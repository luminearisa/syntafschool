<?php

namespace App\Modules\LMS\Http\Resources;

use App\Modules\LMS\Models\LmsBookmark;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LmsBookmark */
class LmsBookmarkResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'course_id' => (int) $this->course_id,
            'learning_item_id' => $this->learning_item_id !== null ? (int) $this->learning_item_id : null,
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
            'course' => $this->whenLoaded('course', fn () => [
                'id' => (int) $this->course->id,
                'name' => (string) $this->course->name,
                'slug' => (string) $this->course->slug,
                'code' => (string) $this->course->code,
                'thumbnail' => $this->course->thumbnail,
                'status' => $this->course->status?->value,
                'visibility' => $this->course->visibility?->value,
            ]),
            'item' => $this->whenLoaded('item', fn () => $this->item === null ? null : [
                'id' => (int) $this->item->id,
                'title' => (string) $this->item->title,
                'item_type' => $this->item->item_type?->value,
                'resource_type' => $this->item->resource_type?->value,
                'activity_type' => $this->item->activity_type?->value,
                'section_name' => $this->item->section?->title,
            ]),
        ];
    }
}
