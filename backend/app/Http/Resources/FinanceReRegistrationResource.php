<?php

namespace App\Http\Resources;

use App\Models\FinanceReRegistration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pendaftaran ulang / Daftar Ulang (§2–§7 Phase 2). `status_label` untuk UI;
 * `invoice`/`billing` dimuat saat penautan tagihan ada; `activated` menandai
 * tahun ajaran berikutnya sudah diaktifkan (§7).
 */
class FinanceReRegistrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'registration_number' => $this->registration_number,
            'student_id' => (int) $this->student_id,
            'student_name' => $this->whenLoaded('student', fn (): ?string => $this->student?->full_name),
            'student_nis' => $this->whenLoaded('student', fn (): ?string => $this->student?->nis),
            'current_academic_year_id' => $this->current_academic_year_id !== null ? (int) $this->current_academic_year_id : null,
            'current_academic_year' => $this->whenLoaded('currentAcademicYear', fn (): ?string => $this->currentAcademicYear?->name),
            'next_academic_year_id' => (int) $this->next_academic_year_id,
            'next_academic_year' => $this->whenLoaded('nextAcademicYear', fn (): ?string => $this->nextAcademicYear?->name),
            'fee_package_id' => $this->fee_package_id !== null ? (int) $this->fee_package_id : null,
            'fee_package_name' => $this->whenLoaded('feePackage', fn (): ?string => $this->feePackage?->name),
            'billing_id' => $this->billing_id !== null ? (int) $this->billing_id : null,
            'billing_number' => $this->whenLoaded('billing', fn (): ?string => $this->billing?->billing_number),
            'invoice_id' => $this->invoice_id !== null ? (int) $this->invoice_id : null,
            'invoice_number' => $this->whenLoaded('invoice', fn (): ?string => $this->invoice?->invoice_number),
            'amount' => (float) $this->amount,
            'discount_amount' => (float) $this->discount_amount,
            'paid_amount' => (float) $this->paid_amount,
            'remaining_amount' => round((float) $this->amount - (float) $this->discount_amount - (float) $this->paid_amount, 2),
            'due_date' => $this->due_date?->toDateString(),
            'cost_center_id' => $this->cost_center_id !== null ? (int) $this->cost_center_id : null,
            'revenue_account_id' => $this->revenue_account_id !== null ? (int) $this->revenue_account_id : null,
            'receivable_account_id' => $this->receivable_account_id !== null ? (int) $this->receivable_account_id : null,
            'status' => $this->status,
            'status_label' => FinanceReRegistration::STATUS_LABELS[$this->status] ?? $this->status,
            'billed_at' => $this->billed_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'activated' => (bool) $this->activated,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
