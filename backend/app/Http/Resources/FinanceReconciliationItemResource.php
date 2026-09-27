<?php

namespace App\Http\Resources;

use App\Models\FinanceReconciliationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baris rekonsiliasi (§44) — satu baris rekening koran vs transaksi sistem.
 */
class FinanceReconciliationItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'reconciliation_id' => (int) $this->reconciliation_id,
            'transaction_date' => $this->transaction_date?->toDateString(),
            'description' => $this->description,
            'bank_amount' => (float) $this->bank_amount,
            'bank_transaction_id' => $this->bank_transaction_id !== null ? (int) $this->bank_transaction_id : null,
            'bank_statement_line_id' => $this->bank_statement_line_id !== null ? (int) $this->bank_statement_line_id : null,
            'journal_line_id' => $this->journal_line_id !== null ? (int) $this->journal_line_id : null,
            'match_status' => $this->match_status,
            'match_status_label' => FinanceReconciliationItem::MATCH_LABELS[$this->match_status] ?? (string) $this->match_status,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
