<?php

namespace App\Http\Resources;

use App\Models\FinanceCashTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transaksi kas standalone (§2). `finance_transaction_number` adalah jurnal yang
 * menggerakkan saldo rekening — tanpanya baris ini hanya catatan, dan §2
 * melarang kas bergerak tanpa jurnal.
 */
class FinanceCashTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'transaction_number' => $this->transaction_number,
            'cash_account_id' => (int) $this->cash_account_id,
            'cash_account_code' => $this->whenLoaded('cashAccount', fn (): ?string => $this->cashAccount?->code),
            'cash_account_name' => $this->whenLoaded('cashAccount', fn (): ?string => $this->cashAccount?->name),
            'transaction_date' => $this->transaction_date?->toDateString(),
            'type' => $this->type,
            'type_label' => FinanceCashTransaction::TYPE_LABELS[$this->type] ?? $this->type,
            'direction' => $this->isInflow() ? 'in' : 'out',
            'amount' => (float) $this->amount,
            'contra_account_id' => $this->contra_account_id !== null ? (int) $this->contra_account_id : null,
            'contra_account_code' => $this->whenLoaded('contraAccount', fn (): ?string => $this->contraAccount?->code),
            'contra_account_name' => $this->whenLoaded('contraAccount', fn (): ?string => $this->contraAccount?->name),
            'reference' => $this->reference,
            'description' => $this->description,
            'cashier_session_id' => $this->cashier_session_id !== null ? (int) $this->cashier_session_id : null,
            'finance_transaction_id' => $this->finance_transaction_id !== null ? (int) $this->finance_transaction_id : null,
            'journal_number' => $this->whenLoaded('financeTransaction', fn (): ?string => $this->financeTransaction?->transaction_number),
            'created_by' => $this->created_by !== null ? (int) $this->created_by : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
