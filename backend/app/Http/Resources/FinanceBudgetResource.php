<?php

namespace App\Http\Resources;

use App\Models\FinanceBudget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Anggaran (§8) + status approval. `remaining` memakai cache `used_amount`;
 * angka §9/§10 yang sebenarnya (actual dari buku besar, committed, available)
 * dihitung `FinanceBudgetService::position()` dan digabung oleh controller pada
 * `show()` — daftarnya sengaja tidak memuatnya supaya tidak jadi N+1 query
 * buku besar per baris.
 */
class FinanceBudgetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'fiscal_year' => (int) $this->fiscal_year,
            'account_id' => (int) $this->account_id,
            'account_code' => $this->whenLoaded('account', fn (): ?string => $this->account?->code),
            'account_name' => $this->whenLoaded('account', fn (): ?string => $this->account?->name),
            'cost_center_id' => $this->cost_center_id !== null ? (int) $this->cost_center_id : null,
            'cost_center_name' => $this->whenLoaded('costCenter', fn (): ?string => $this->costCenter?->name),
            'period' => $this->period,
            'budget_amount' => (float) $this->budget_amount,
            'used_amount' => (float) $this->used_amount,
            'remaining' => round((float) $this->budget_amount - (float) $this->used_amount, 2),
            'status' => $this->status,
            'status_label' => FinanceBudget::STATUS_LABELS[$this->status] ?? $this->status,
            'submitted_by' => $this->submitted_by !== null ? (int) $this->submitted_by : null,
            'approved_by' => $this->approved_by !== null ? (int) $this->approved_by : null,
            'approved_by_name' => $this->whenLoaded('approvedBy', fn (): ?string => $this->approvedBy?->name),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'reject_reason' => $this->reject_reason,
            'closed_by' => $this->closed_by !== null ? (int) $this->closed_by : null,
            'closed_by_name' => $this->whenLoaded('closedBy', fn (): ?string => $this->closedBy?->name),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'cancelled_by' => $this->cancelled_by !== null ? (int) $this->cancelled_by : null,
            'cancelled_by_name' => $this->whenLoaded('cancelledBy', fn (): ?string => $this->cancelledBy?->name),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
