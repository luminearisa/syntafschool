<?php

namespace App\Http\Resources;

use App\Models\FinanceScholarship;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Beasiswa (§18): pengurang tagihan per santri (nominal/persen), opsional terikat
 * satu jenis tagihan dan rentang tanggal. Status draft→active lewat approval.
 */
class FinanceScholarshipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $labels = [
            FinanceScholarship::STATUS_DRAFT => 'Draft',
            FinanceScholarship::STATUS_ACTIVE => 'Aktif',
            FinanceScholarship::STATUS_EXPIRED => 'Kedaluwarsa',
            FinanceScholarship::STATUS_REJECTED => 'Ditolak',
            FinanceScholarship::STATUS_CANCELLED => 'Dibatalkan',
        ];

        return [
            'id' => (int) $this->id,
            'student_id' => (int) $this->student_id,
            'student_name' => $this->whenLoaded('student', fn (): ?string => $this->student?->full_name),
            'type' => $this->type,
            'type_label' => $this->type === FinanceScholarship::TYPE_PERCENTAGE ? 'Persentase' : 'Nominal',
            'amount' => (float) $this->amount,
            'percentage' => (float) $this->percentage,
            'fee_type_id' => $this->fee_type_id !== null ? (int) $this->fee_type_id : null,
            'fee_type_name' => $this->whenLoaded('feeType', fn (): ?string => $this->feeType?->name),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'reason' => $this->reason,
            'status' => $this->status,
            'status_label' => $labels[$this->status] ?? $this->status,
            'approved_by' => $this->approved_by !== null ? (int) $this->approved_by : null,
            'approved_by_name' => $this->whenLoaded('approvedBy', fn (): ?string => $this->approvedBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
