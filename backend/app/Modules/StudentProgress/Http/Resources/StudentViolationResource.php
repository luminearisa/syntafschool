<?php

namespace App\Modules\StudentProgress\Http\Resources;

use App\Modules\StudentProgress\Models\StudentViolation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu pelanggaran siswa. `point` adalah snapshot bobot master saat kejadian.
 */
class StudentViolationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'student_id' => (int) $this->student_id,
            'student' => $this->when(
                $this->relationLoaded('student') && $this->student !== null,
                fn (): array => [
                    'id' => (int) $this->student->id,
                    'nis' => $this->student->nis,
                    'full_name' => $this->student->full_name,
                ],
            ),
            'violation_type_id' => (int) $this->violation_type_id,
            'violation_type' => $this->when(
                $this->relationLoaded('violationType') && $this->violationType !== null,
                fn (): array => [
                    'id' => (int) $this->violationType->id,
                    'name' => $this->violationType->name,
                    'category' => $this->violationType->category,
                    'master_point' => (int) $this->violationType->point,
                ],
            ),
            'violation_type_name' => $this->violationType?->name,
            'category' => $this->violationType?->category,
            'date' => $this->date?->toDateString(),
            'description' => $this->description,
            'point' => (int) $this->point,
            'reported_by' => $this->reported_by === null ? null : (int) $this->reported_by,
            'reporter_name' => $this->whenLoaded('reporter', fn () => $this->reporter?->full_name),
            'action_taken' => $this->action_taken,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'can_verify' => $this->isRecorded(),
            'can_resolve' => $this->isVerified(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
