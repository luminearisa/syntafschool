<?php

namespace App\Modules\Promotion\Http\Resources;

use App\Modules\Promotion\Models\Graduation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GraduationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Graduation $graduation */
        $graduation = $this->resource;

        return [
            'id' => (int) $graduation->id,
            'student_id' => (int) $graduation->student_id,
            'academic_year_id' => (int) $graduation->academic_year_id,
            'graduation_number' => $graduation->graduation_number,
            'decision' => $graduation->decision,
            'decision_label' => Graduation::DECISION_LABELS[$graduation->decision] ?? $graduation->decision,
            'average_score' => $graduation->average_score === null ? null : (float) $graduation->average_score,
            'criteria_summary' => $graduation->criteria_summary ?? [],
            'certificate_path' => $graduation->certificate_path,
            'notes' => $graduation->notes,
            'approved_by' => $graduation->approved_by === null ? null : (int) $graduation->approved_by,
            'approved_at' => $graduation->approved_at?->toIso8601String(),
            'student' => $this->whenLoaded('student', fn () => $graduation->student ? [
                'id' => (int) $graduation->student->id,
                'nis' => $graduation->student->nis,
                'full_name' => $graduation->student->full_name,
            ] : null),
            'academic_year' => $this->whenLoaded('academicYear', fn () => $graduation->academicYear ? [
                'id' => (int) $graduation->academicYear->id,
                'name' => $graduation->academicYear->name,
            ] : null),
            'created_at' => $graduation->created_at?->toIso8601String(),
            'updated_at' => $graduation->updated_at?->toIso8601String(),
        ];
    }
}
