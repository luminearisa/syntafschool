<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baris rekening koran (Phase 4 §4) beserta hasil pencocokannya.
 *
 * `match_method` ikut ditampilkan karena pencocokan otomatis harus bisa
 * dipertanyakan di layar review, bukan dipercaya buta; `journal_*` menunjuk baris
 * jurnal buku kita yang jadi lawan — rantai menuju dokumen sumbernya lewat
 * `/finance/journals/{id}/trace`.
 */
class FinanceBankStatementLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'bank_statement_id' => (int) $this->bank_statement_id,
            'sequence' => $this->sequence !== null ? (int) $this->sequence : null,
            'line_date' => $this->line_date?->toDateString(),
            'reference' => $this->reference,
            'description' => $this->description,
            'debit' => (float) $this->debit,
            'credit' => (float) $this->credit,
            'amount' => (float) $this->amount,
            'direction' => $this->isInflow() ? 'in' : 'out',
            'running_balance' => $this->running_balance !== null ? (float) $this->running_balance : null,
            'external_id' => $this->external_id,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'match_method' => $this->match_method,
            'journal_line_id' => $this->journal_line_id !== null ? (int) $this->journal_line_id : null,
            'journal_number' => $this->whenLoaded(
                'journalLine',
                fn (): ?string => $this->journalLine?->transaction?->transaction_number,
            ),
            'journal_amount' => $this->whenLoaded(
                'journalLine',
                fn (): ?float => $this->journalLine === null
                    ? null
                    : round((float) $this->journalLine->debit + (float) $this->journalLine->credit, 2),
            ),
            'matched_by' => $this->matched_by !== null ? (int) $this->matched_by : null,
            'matched_at' => $this->matched_at?->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}
