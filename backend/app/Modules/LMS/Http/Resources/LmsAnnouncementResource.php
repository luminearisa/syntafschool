<?php

namespace App\Modules\LMS\Http\Resources;

use App\Modules\LMS\Models\LmsCourseAnnouncement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LmsCourseAnnouncement */
class LmsAnnouncementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'course_id' => (int) $this->course_id,
            'title' => (string) $this->title,
            'content' => $this->content,
            'pinned' => (bool) $this->pinned,
            'published_at' => $this->published_at?->toIso8601String(),
            'is_published' => $this->published_at !== null && $this->published_at->isPast(),
            'created_by' => $this->created_by !== null ? (int) $this->created_by : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
