<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TranscriptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'student_id' => (int) $this->student_id,
            'academic_year_id' => $this->academic_year_id === null ? null : (int) $this->academic_year_id,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'generated_by' => $this->generated_by === null ? null : (int) $this->generated_by,
            'data' => $this->data ?? [],
            'student' => $this->whenLoaded('student', fn () => $this->student ? [
                'id' => (int) $this->student->id,
                'nis' => $this->student->nis,
                'full_name' => $this->student->full_name,
            ] : null),
            'academic_year' => $this->whenLoaded('academicYear', fn () => $this->academicYear ? [
                'id' => (int) $this->academicYear->id,
                'name' => $this->academicYear->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
