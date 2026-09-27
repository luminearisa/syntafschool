<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transaksi bank (§41) — deposit/withdrawal/transfer/fee. `finance_transaction_id`
 * menaut ke jurnal double-entry yang menggerakkan saldo rekening.
 */
class FinanceBankTransactionResource extends JsonResource
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
            'transaction_date' => $this->transaction_date?->toDateString(),
            'type' => $this->type,
            'amount' => (float) $this->amount,
            'reference' => $this->reference,
            'description' => $this->description,
            'transfer_id' => $this->transfer_id !== null ? (int) $this->transfer_id : null,
            'transfer_number' => $this->whenLoaded('transfer', fn (): ?string => $this->transfer?->transfer_number),
            'finance_transaction_id' => $this->finance_transaction_id !== null ? (int) $this->finance_transaction_id : null,
            'created_by' => $this->created_by !== null ? (int) $this->created_by : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
