<?php

namespace App\Http\Resources;

use App\Models\FinanceBankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rekening bank (§40). `bank_name` dari master `banks` existing (§71 — tidak
 * menduplikasi institusi bank); `current_balance` digerakkan ledger engine.
 */
class FinanceBankAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'bank_id' => $this->bank_id !== null ? (int) $this->bank_id : null,
            'bank_name' => $this->whenLoaded('bank', fn (): ?string => $this->bank?->name),
            'account_number' => $this->account_number,
            'account_name' => $this->account_name,
            'branch' => $this->branch,
            'account_type' => $this->account_type,
            'account_type_label' => FinanceBankAccount::TYPE_LABELS[$this->account_type] ?? $this->account_type,
            'finance_account_id' => (int) $this->finance_account_id,
            'finance_account_code' => $this->whenLoaded('financeAccount', fn (): ?string => $this->financeAccount?->code),
            'finance_account_name' => $this->whenLoaded('financeAccount', fn (): ?string => $this->financeAccount?->name),
            'cost_center_id' => $this->cost_center_id !== null ? (int) $this->cost_center_id : null,
            'cost_center_name' => $this->whenLoaded('costCenter', fn (): ?string => $this->costCenter?->name),
            'opening_balance' => (float) $this->opening_balance,
            'current_balance' => (float) $this->current_balance,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
