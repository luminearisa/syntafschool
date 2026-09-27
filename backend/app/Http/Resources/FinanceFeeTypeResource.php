<?php

namespace App\Http\Resources;

use App\Models\FinanceFeeType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Jenis tagihan (§10). `account_code`/`account_name` = akun pendapatan COA
 * tempat tagihan ini di-kredit saat dibayar.
 */
class FinanceFeeTypeResource extends JsonResource
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
            'category' => $this->category,
            'account_id' => $this->account_id !== null ? (int) $this->account_id : null,
            'account_code' => $this->whenLoaded('account', fn (): ?string => $this->account?->code),
            'account_name' => $this->whenLoaded('account', fn (): ?string => $this->account?->name),
            'receivable_account_id' => $this->receivable_account_id !== null ? (int) $this->receivable_account_id : null,
            'receivable_account_code' => $this->whenLoaded('receivableAccount', fn (): ?string => $this->receivableAccount?->code),
            'receivable_account_name' => $this->whenLoaded('receivableAccount', fn (): ?string => $this->receivableAccount?->name),
            'default_amount' => (float) $this->default_amount,
            'frequency' => $this->frequency,
            'frequency_label' => FinanceFeeType::FREQUENCY_LABELS[$this->frequency] ?? $this->frequency,
            'is_active' => (bool) $this->is_active,
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
