<?php

namespace App\Modules\LMS\Http\Resources;

use App\Modules\LMS\Models\LmsGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LmsGroup */
class LmsGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'course_id' => (int) $this->course_id,
            'name' => (string) $this->name,
            'description' => $this->description,
            'capacity' => $this->capacity !== null ? (int) $this->capacity : null,
            'sort_order' => (int) $this->sort_order,
            'members' => $this->whenLoaded('members', fn () => $this->members->map(fn ($member) => [
                'id' => (int) $member->id,
                'enrollment_id' => (int) $member->enrollment_id,
                'user_id' => (int) $member->user_id,
            ])->values()->all()),
            'members_count' => $this->whenCounted('members'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
