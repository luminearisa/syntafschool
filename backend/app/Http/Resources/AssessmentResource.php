<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuk respons satu penilaian.
 *
 * `class_id` dan `teacher_id` dipakai di API (bukan `school_class_id` /
 * `employee_id` seperti nama kolomnya) agar konsisten dengan modul lain.
 */
class AssessmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'class_id' => $this->school_class_id,
            'subject_id' => $this->subject_id,
            'semester_id' => $this->semester_id,
            'teacher_id' => $this->employee_id,
            'assessment_type_id' => $this->assessment_type_id,
            'name' => $this->name,
            'description' => $this->description,
            'max_score' => (float) $this->max_score,
            'weight' => $this->weight === null ? null : (float) $this->weight,
            'date' => $this->date?->toDateString(),
            'status' => $this->status,
            'grading_formula_id' => $this->grading_formula_id,
            'extra' => $this->extra,

            'type' => $this->whenLoaded('assessmentType', fn () => $this->assessmentType ? [
                'id' => $this->assessmentType->id,
                'code' => $this->assessmentType->code,
                'name' => $this->assessmentType->name,
                'category' => $this->assessmentType->category,
            ] : null),
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
            'semester' => $this->whenLoaded('semester', fn () => $this->semester ? [
                'id' => $this->semester->id,
                'name' => $this->semester->name,
            ] : null),
            'teacher' => $this->whenLoaded('teacher', fn () => $this->teacher ? [
                'id' => $this->teacher->id,
                'nip' => $this->teacher->nip,
                'full_name' => $this->teacher->full_name,
            ] : null),

            // Diisi lewat withCount() pada controller.
            'scores_count' => (int) ($this->scores_count ?? 0),
            'graded_count' => (int) ($this->graded_count ?? 0),
            'remedials_count' => (int) ($this->remedials_count ?? 0),

            'is_locked' => $this->isLocked(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
