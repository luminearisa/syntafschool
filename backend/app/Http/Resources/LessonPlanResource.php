<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LessonPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'teacher_id' => $this->teacher_id,
            'subject_id' => $this->subject_id,
            'education_level_id' => $this->education_level_id,
            'class_id' => $this->class_id,
            'semester_id' => $this->semester_id,
            'code' => $this->code,
            'title' => $this->title,
            'learning_objectives' => $this->learning_objectives ?? [],
            'activities' => $this->activities ?? [],
            'assessment_plan' => $this->assessment_plan,
            'media' => $this->media,
            'references' => $this->references,
            'duration_jp' => (int) $this->duration_jp,
            'status' => $this->status,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'attachment_path' => $this->attachment_path,
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'is_editable' => $this->isEditable(),

            'teacher' => $this->whenLoaded('teacher', fn () => $this->teacher ? [
                'id' => $this->teacher->id,
                'nip' => $this->teacher->nip,
                'full_name' => $this->teacher->full_name,
            ] : null),
            'subject' => $this->whenLoaded('subject', fn () => $this->subject ? [
                'id' => $this->subject->id,
                'code' => $this->subject->code,
                'name' => $this->subject->name,
            ] : null),
            'education_level' => $this->whenLoaded('educationLevel', fn () => $this->educationLevel ? [
                'id' => $this->educationLevel->id,
                'name' => $this->educationLevel->name,
            ] : null),
            'school_class' => $this->whenLoaded('schoolClass', fn () => $this->schoolClass ? [
                'id' => $this->schoolClass->id,
                'name' => $this->schoolClass->name,
                'code' => $this->schoolClass->code,
            ] : null),
            'semester' => $this->whenLoaded('semester', fn () => $this->semester ? [
                'id' => $this->semester->id,
                'name' => $this->semester->name,
            ] : null),
            'approver' => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
            ] : null),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
