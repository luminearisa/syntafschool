<?php

namespace App\Modules\Promotion\Http\Resources;

use App\Modules\Promotion\Models\PromotionDecision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionDecisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PromotionDecision $decision */
        $decision = $this->resource;

        return [
            'id' => (int) $decision->id,
            'student_id' => (int) $decision->student_id,
            'class_id' => (int) $decision->school_class_id,
            'target_class_id' => $decision->target_class_id === null ? null : (int) $decision->target_class_id,
            'academic_year_id' => (int) $decision->academic_year_id,
            'decision' => $decision->decision,
            'decision_label' => $decision->decision === null
                ? null
                : (PromotionDecision::DECISION_LABELS[$decision->decision] ?? $decision->decision),
            'status' => $decision->status,
            'status_label' => PromotionDecision::STATUS_LABELS[$decision->status] ?? $decision->status,
            'snapshot' => $decision->snapshot ?? [],
            'reason' => $decision->reason,
            'decided_by' => $decision->decided_by === null ? null : (int) $decision->decided_by,
            'decided_at' => $decision->decided_at?->toIso8601String(),
            'student' => $this->whenLoaded('student', fn () => $decision->student ? [
                'id' => (int) $decision->student->id,
                'nis' => $decision->student->nis,
                'full_name' => $decision->student->full_name,
            ] : null),
            'school_class' => $this->whenLoaded('schoolClass', fn () => $decision->schoolClass ? [
                'id' => (int) $decision->schoolClass->id,
                'name' => $decision->schoolClass->name,
                'code' => $decision->schoolClass->code,
            ] : null),
            'target_class' => $this->whenLoaded('targetClass', fn () => $decision->targetClass ? [
                'id' => (int) $decision->targetClass->id,
                'name' => $decision->targetClass->name,
                'code' => $decision->targetClass->code,
            ] : null),
            'academic_year' => $this->whenLoaded('academicYear', fn () => $decision->academicYear ? [
                'id' => (int) $decision->academicYear->id,
                'name' => $decision->academicYear->name,
            ] : null),
            'approvals' => $this->whenLoaded('approvals', fn () => PromotionApprovalResource::collection($decision->approvals)),
            'created_at' => $decision->created_at?->toIso8601String(),
            'updated_at' => $decision->updated_at?->toIso8601String(),
        ];
    }
}
