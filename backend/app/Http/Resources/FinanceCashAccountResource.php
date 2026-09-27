<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Akun kas (§39). `current_balance` digerakkan ledger engine; dibaca-saja di API.
 */
class FinanceCashAccountResource extends JsonResource
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
            'account_id' => (int) $this->account_id,
            'account_code' => $this->whenLoaded('account', fn (): ?string => $this->account?->code),
            'account_name' => $this->whenLoaded('account', fn (): ?string => $this->account?->name),
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
