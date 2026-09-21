<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'class_id' => $this->class_id,
            'subject_id' => $this->subject_id,
            'teacher_id' => $this->teacher_id,
            'semester_id' => $this->semester_id,
            'title' => $this->title,
            'description' => $this->description,
            'instructions' => $this->instructions,
            'type' => $this->type,
            'max_score' => (float) $this->max_score,
            'weight' => (float) $this->weight,
            'assigned_date' => $this->assigned_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'allow_late_submission' => (bool) $this->allow_late_submission,
            'attachment_path' => $this->attachment_path,
            'status' => $this->status,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_by' => $this->created_by,

            // Progres pengumpulan — diisi lewat withCount() pada controller.
            'submission_count' => (int) ($this->submissions_count ?? 0),
            'graded_count' => (int) ($this->graded_count ?? 0),
            'is_overdue' => (bool) $this->isOverdue(),

            'school_class' => $this->whenLoaded('schoolClass', fn () => $this->schoolClass ? [
                'id' => $this->schoolClass->id,
                'name' => $this->schoolClass->name,
                'code' => $this->schoolClass->code,
            ] : null),
            'subject' => $this->whenLoaded('subject', fn () => $this->subject ? [
                'id' => $this->subject->id,
                'code' => $this->subject->code,
                'name' => $this->subject->name,
            ] : null),
            'teacher' => $this->whenLoaded('teacher', fn () => $this->teacher ? [
                'id' => $this->teacher->id,
                'nip' => $this->teacher->nip,
                'full_name' => $this->teacher->full_name,
            ] : null),
            'semester' => $this->whenLoaded('semester', fn () => $this->semester ? [
                'id' => $this->semester->id,
                'name' => $this->semester->name,
            ] : null),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
