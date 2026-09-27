<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sesi kasir (§22, §23). `expected_cash`/`difference` dihitung service saat tutup;
 * `status_label` untuk UI. `payments_count` memberi gambaran volume tanpa memuat semua.
 */
class FinanceCashierSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'session_number' => $this->session_number,
            'cashier_id' => (int) $this->cashier_id,
            'cashier_name' => $this->whenLoaded('cashier', fn (): ?string => $this->cashier?->name),
            'cash_account_id' => (int) $this->cash_account_id,
            'cash_account_name' => $this->whenLoaded('cashAccount', fn (): ?string => $this->cashAccount?->name),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'opening_cash' => (float) $this->opening_cash,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'expected_cash' => $this->expected_cash !== null ? (float) $this->expected_cash : null,
            'actual_cash' => $this->actual_cash !== null ? (float) $this->actual_cash : null,
            'difference' => $this->difference !== null ? (float) $this->difference : null,
            'difference_reason' => $this->difference_reason,
            'status' => $this->status,
            'status_label' => $this->resource->statusLabel(),
            'reviewed_by' => $this->reviewed_by !== null ? (int) $this->reviewed_by : null,
            'reviewed_by_name' => $this->whenLoaded('reviewedBy', fn (): ?string => $this->reviewedBy?->name),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'notes' => $this->notes,
            'payments_count' => $this->whenCounted('payments'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
