<?php

namespace App\Modules\Mutation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representasi permohonan mutasi siswa untuk API.
 */
class StudentTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'type' => $this->type,
            'type_label' => $this->typeLabel(),
            'academic_year_id' => $this->academic_year_id,
            'from_class_id' => $this->from_class_id,
            'to_class_id' => $this->to_class_id,
            'from_school_name' => $this->from_school_name,
            'to_school_name' => $this->to_school_name,
            'request_date' => $this->request_date?->toDateString(),
            'effective_date' => $this->effective_date?->toDateString(),
            'reason' => $this->reason,
            'document_number' => $this->document_number,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'requested_by' => $this->requested_by,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'notes' => $this->notes,

            'student' => $this->whenLoaded('student', fn () => $this->student ? [
                'id' => $this->student->id,
                'nis' => $this->student->nis,
                'nisn' => $this->student->nisn,
                'full_name' => $this->student->full_name,
            ] : null),

            'from_class' => $this->whenLoaded('fromClass', fn () => $this->ref($this->fromClass)),
            'to_class' => $this->whenLoaded('toClass', fn () => $this->ref($this->toClass)),
            'academic_year' => $this->whenLoaded('academicYear', fn () => $this->ref($this->academicYear)),
            'requester' => $this->whenLoaded('requester', fn () => $this->ref($this->requester)),
            'approver' => $this->whenLoaded('approver', fn () => $this->ref($this->approver)),

            'histories' => $this->whenLoaded(
                'histories',
                fn () => StudentTransferHistoryResource::collection($this->histories),
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: mixed, name: string}|null
     */
    private function ref(mixed $model): ?array
    {
        if (! $model) {
            return null;
        }

        return [
            'id' => $model->id,
            'name' => $model->name ?? (string) $model->id,
        ];
    }
}
