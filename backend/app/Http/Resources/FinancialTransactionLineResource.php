<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baris jurnal (§5, §35): akun + sisi debit/kredit + cost center + referensi
 * dokumen. `normal_balance` ikut dikirim supaya pembaca API bisa menafsirkan
 * arah saldo tanpa menebak tipe akun (§37).
 */
class FinancialTransactionLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'account_id' => (int) $this->account_id,
            'account_code' => $this->whenLoaded('account', fn (): ?string => $this->account?->code),
            'account_name' => $this->whenLoaded('account', fn (): ?string => $this->account?->name),
            'account_type' => $this->whenLoaded('account', fn (): ?string => $this->account?->type),
            'normal_balance' => $this->whenLoaded('account', fn (): ?string => $this->account?->normal_balance),
            'debit' => (float) $this->debit,
            'credit' => (float) $this->credit,
            // Nominal dilihat dari sisi normal akun: bertambah = positif.
            'movement' => $this->whenLoaded(
                'account',
                fn (): ?float => $this->account === null
                    ? null
                    : ($this->account->normal_balance === 'debit'
                        ? round((float) $this->debit - (float) $this->credit, 2)
                        : round((float) $this->credit - (float) $this->debit, 2)),
            ),
            'description' => $this->description,
            'reference' => $this->reference,
            'cost_center_id' => $this->cost_center_id !== null ? (int) $this->cost_center_id : null,
            'cost_center_name' => $this->whenLoaded('costCenter', fn (): ?string => $this->costCenter?->name),
        ];
    }
}
