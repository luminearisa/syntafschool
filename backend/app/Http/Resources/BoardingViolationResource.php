<?php

namespace App\Http\Resources;

use App\Models\BoardingViolation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu catatan pelanggaran santri (§36). `points` adalah snapshot bobot master
 * pada saat kejadian — mengubah `boarding_violation_types.point` setelahnya
 * tidak mengoreksi histori.
 */
class BoardingViolationResource extends JsonResource
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
                    'full_name' => $this->student->full_name,
                    'nis' => $this->student->nis,
                ],
            ),
            'violation_type_id' => $this->violation_type_id === null ? null : (int) $this->violation_type_id,
            'type' => $this->when(
                $this->relationLoaded('type') && $this->type !== null,
                fn (): array => [
                    'id' => (int) $this->type->id,
                    'code' => $this->type->code,
                    'name' => $this->type->name,
                    'category' => $this->type->category,
                    'category_label' => $this->type->categoryLabel(),
                    'severity' => $this->type->severity,
                    'severity_label' => $this->type->severityLabel(),
                    'master_point' => (int) $this->type->point,
                ],
            ),
            'type_name' => $this->type?->name,
            'category' => $this->type?->category,
            'severity' => $this->type?->severity,
            'date' => $this->date?->toDateString(),
            'narrative' => $this->narrative,
            'points' => (int) $this->points,
            'evidence_path' => $this->evidence_path,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'is_open' => $this->isOpen(),
            'can_mark_actioned' => $this->status === BoardingViolation::STATUS_OPEN,
            'can_close' => $this->status !== BoardingViolation::STATUS_CLOSED,
            'recorded_by' => $this->recorded_by === null ? null : (int) $this->recorded_by,
            'recorded_by_name' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->name),
            'disciplinary_actions_count' => $this->whenCounted('disciplinaryActions'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
