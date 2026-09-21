<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pengumuman PPDB.
 */
class PpdbAnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period_id' => $this->period_id,
            'title' => $this->title,
            'body' => $this->body,
            'channel' => $this->channel,
            'status' => $this->status,
            'is_published' => $this->isPublished(),
            'published_at' => $this->published_at?->toIso8601String(),
            'published_by' => $this->published_by,
            'published_by_name' => $this->whenLoaded('publishedBy', fn () => $this->publishedBy?->name),
            'period' => $this->whenLoaded('period', fn () => $this->period ? [
                'id' => $this->period->id,
                'code' => $this->period->code,
                'name' => $this->period->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
