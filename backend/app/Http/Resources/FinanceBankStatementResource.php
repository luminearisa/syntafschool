<?php

namespace App\Http\Resources;

use App\Models\FinanceBankStatement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Header rekening koran (Phase 4 §4).
 *
 * Hanya DATA BANK: tidak ada kolom saldo buku di modelnya, jadi pembanding
 * (`Book Balance`) datang dari `summary`/`movements`, bukan dari baris ini —
 * satu sumber kebenaran untuk angka buku (§1).
 */
class FinanceBankStatementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'statement_number' => $this->statement_number,
            'bank_account_id' => (int) $this->bank_account_id,
            'bank_account_code' => $this->whenLoaded('bankAccount', fn (): ?string => $this->bankAccount?->code),
            'bank_account_name' => $this->whenLoaded('bankAccount', fn (): ?string => $this->bankAccount?->name),
            'statement_date' => $this->statement_date?->toDateString(),
            'opening_balance' => (float) $this->opening_balance,
            'closing_balance' => (float) $this->closing_balance,
            'source' => $this->source,
            'external_reference' => $this->external_reference,
            'filename' => $this->filename,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'is_editable' => $this->isEditable(),
            'notes' => $this->notes,
            'line_count' => $this->whenCounted('lines'),
            'lines' => FinanceBankStatementLineResource::collection($this->whenLoaded('lines')),
            'reconciliation_ids' => $this->whenLoaded(
                'reconciliations',
                fn (): array => $this->reconciliations->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            ),
            'created_by' => $this->created_by !== null ? (int) $this->created_by : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** Status + label untuk meta API. */
    public static function statuses(): array
    {
        return FinanceBankStatement::STATUS_LABELS;
    }
}
