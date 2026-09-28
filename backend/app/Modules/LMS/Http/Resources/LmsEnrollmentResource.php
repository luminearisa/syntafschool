<?php

namespace App\Modules\LMS\Http\Resources;

use App\Modules\LMS\Models\LmsEnrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LmsEnrollment */
class LmsEnrollmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'course_id' => (int) $this->course_id,
            'user_id' => (int) $this->user_id,
            'student_id' => $this->student_id !== null ? (int) $this->student_id : null,
            'teacher_id' => $this->teacher_id !== null ? (int) $this->teacher_id : null,
            'role' => $this->role?->value,
            'role_label' => $this->role?->label(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'enrollment_method' => $this->enrollment_method?->value,
            'enrollment_method_label' => $this->enrollment_method?->label(),
            'enrolled_at' => $this->enrolled_at?->toIso8601String(),
            'start_at' => $this->start_at?->toIso8601String(),
            'end_at' => $this->end_at?->toIso8601String(),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'last_accessed_at' => $this->last_accessed_at?->toIso8601String(),
            'progress_percent' => (float) $this->progress_percent,
            'metadata' => $this->metadata,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => (int) $this->user->id,
                'name' => (string) $this->user->name,
                'email' => $this->user->email,
                'avatar_path' => $this->user->avatar_path ?? null,
            ]),
            'progress' => new LmsProgressResource($this->whenLoaded('progress')),
            'course' => new LmsCourseResource($this->whenLoaded('course')),
        ];
    }
}
