<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignmentSubmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'assignment_id' => $this->assignment_id,
            'student_id' => $this->student_id,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'content' => $this->content,
            'attachment_path' => $this->attachment_path,
            'score' => $this->score !== null ? (float) $this->score : null,
            'feedback' => $this->feedback,
            'graded_by' => $this->graded_by,
            'graded_at' => $this->graded_at?->toIso8601String(),
            'status' => $this->status,
            'is_late' => $this->status === 'late',
            'student' => $this->whenLoaded('student', fn () => $this->student ? [
                'id' => $this->student->id,
                'nis' => $this->student->nis,
                'full_name' => $this->student->full_name,
            ] : null),
            'grader' => $this->whenLoaded('grader', fn () => $this->grader ? [
                'id' => $this->grader->id,
                'name' => $this->grader->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
