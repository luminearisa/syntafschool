<?php

namespace App\Modules\LMS\Http\Resources;

use App\Modules\LMS\Models\LmsCourseProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LmsCourseProgress */
class LmsProgressResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        if ($this->resource === null) {
            return [];
        }

        return [
            'id' => (int) $this->id,
            'enrollment_id' => (int) $this->enrollment_id,
            'course_id' => (int) $this->course_id,
            'total_items' => (int) $this->total_items,
            'required_items' => (int) $this->required_items,
            'completed_items' => (int) $this->completed_items,
            'progress_percent' => (float) $this->progress_percent,
            'last_item_id' => $this->last_item_id !== null ? (int) $this->last_item_id : null,
            'next_item_id' => $this->next_item_id !== null ? (int) $this->next_item_id : null,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'computed_at' => $this->computed_at?->toIso8601String(),
        ];
    }
}
