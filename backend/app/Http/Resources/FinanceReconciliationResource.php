<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rekonsiliasi bank (§44, §45). `difference = bank_balance - system_balance`;
 * status reconciled bila selisih nol dalam toleransi.
 */
class FinanceReconciliationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'bank_account_id' => (int) $this->bank_account_id,
            'bank_account_name' => $this->whenLoaded('bankAccount', fn (): ?string => $this->bankAccount?->name),
            'bank_statement_id' => $this->bank_statement_id !== null ? (int) $this->bank_statement_id : null,
            'bank_statement_number' => $this->whenLoaded('bankStatement', fn (): ?string => $this->bankStatement?->statement_number),
            'statement_date' => $this->statement_date?->toDateString(),
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'bank_balance' => (float) $this->bank_balance,
            'system_balance' => (float) $this->system_balance,
            'difference' => (float) $this->difference,
            'status' => $this->status,
            'status_label' => $this->resource->statusLabel(),
            'reconciled_by' => $this->reconciled_by !== null ? (int) $this->reconciled_by : null,
            'reconciled_at' => $this->reconciled_at?->toIso8601String(),
            'notes' => $this->notes,
            'items' => FinanceReconciliationItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
