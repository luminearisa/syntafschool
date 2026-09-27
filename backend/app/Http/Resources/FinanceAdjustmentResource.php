<?php

namespace App\Http\Resources;

use App\Models\FinanceAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Adjustment / koreksi (§51). Diposting sebagai jurnal baru (tidak mengedit
 * transaksi lama); `finance_transaction_id` menaut ke jurnal koreksi.
 */
class FinanceAdjustmentResource extends JsonResource
{
    private const DIRECTION_LABELS = [
        FinanceAdjustment::DIRECTION_INCREASE => 'Penambahan',
        FinanceAdjustment::DIRECTION_DECREASE => 'Pengurangan',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'adjustment_number' => $this->adjustment_number,
            'kind' => $this->kind,
            'kind_label' => $this->resource->kindLabel(),
            'invoice_id' => $this->invoice_id !== null ? (int) $this->invoice_id : null,
            'invoice_number' => $this->whenLoaded('invoice', fn (): ?string => $this->invoice?->invoice_number),
            'account_id' => (int) $this->account_id,
            'account_code' => $this->whenLoaded('account', fn (): ?string => $this->account?->code),
            'account_name' => $this->whenLoaded('account', fn (): ?string => $this->account?->name),
            'contra_account_id' => $this->contra_account_id !== null ? (int) $this->contra_account_id : null,
            'contra_account_code' => $this->whenLoaded('contraAccount', fn (): ?string => $this->contraAccount?->code),
            'contra_account_name' => $this->whenLoaded('contraAccount', fn (): ?string => $this->contraAccount?->name),
            'direction' => $this->direction,
            'direction_label' => self::DIRECTION_LABELS[$this->direction] ?? (string) $this->direction,
            'amount' => (float) $this->amount,
            'adjustment_date' => $this->adjustment_date?->toDateString(),
            'reason' => $this->reason,
            'cost_center_id' => $this->cost_center_id !== null ? (int) $this->cost_center_id : null,
            'cost_center_name' => $this->whenLoaded('costCenter', fn (): ?string => $this->costCenter?->name),
            'cash_account_id' => $this->cash_account_id !== null ? (int) $this->cash_account_id : null,
            'bank_account_id' => $this->bank_account_id !== null ? (int) $this->bank_account_id : null,
            'status' => $this->status,
            'status_label' => $this->resource->statusLabel(),
            'requested_by' => $this->requested_by !== null ? (int) $this->requested_by : null,
            'approved_by' => $this->approved_by !== null ? (int) $this->approved_by : null,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'reject_reason' => $this->reject_reason,
            'finance_transaction_id' => $this->finance_transaction_id !== null ? (int) $this->finance_transaction_id : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
