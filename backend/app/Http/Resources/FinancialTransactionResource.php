<?php

namespace App\Http\Resources;

use App\Models\FinancialTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Header jurnal (§3–§5, §47). `finance_transactions` MEMANG jurnal — tidak ada
 * tabel `finance_journals` kedua (§71), jadi resource inilah tampilan jurnal
 * umum: nomor JRN, tanggal, tipe peristiwa bisnis, status posted/reversed,
 * siapa yang mem-posting, dan baris debit/kreditnya.
 *
 * Semua field bersumber dari kolom/relasi yang sudah ada; `document_*` dibaca
 * dari `metadata` (jejak dokumen sumber §38) agar nomor tagihan/pembayaran ikut
 * tampil tanpa query tambahan.
 */
class FinancialTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'transaction_number' => $this->transaction_number,
            'journal_number' => $this->transaction_number,
            'transaction_date' => $this->transaction_date?->toDateString(),
            'description' => $this->description,
            'type' => $this->type,
            'type_label' => $this->resource->typeLabel(),
            'status' => $this->status,
            'status_label' => $this->status === FinancialTransaction::STATUS_POSTED ? 'Diposting' : 'Dibatalkan',
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id !== null ? (int) $this->reference_id : null,
            'document_number' => $this->metadata['document']['number'] ?? null,
            'total_debit' => (float) $this->total_debit,
            'total_credit' => (float) $this->total_credit,
            'is_balanced' => $this->resource->isBalanced(),
            'cost_center_id' => $this->cost_center_id !== null ? (int) $this->cost_center_id : null,
            'cost_center_name' => $this->whenLoaded('costCenter', fn (): ?string => $this->costCenter?->name),
            'period_id' => $this->period_id !== null ? (int) $this->period_id : null,
            'period_code' => $this->whenLoaded('period', fn (): ?string => $this->period?->code),
            'basis' => $this->metadata['basis'] ?? null,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'posted_by' => $this->posted_by !== null ? (int) $this->posted_by : null,
            'posted_by_name' => $this->whenLoaded('postedBy', fn (): ?string => $this->postedBy?->name),
            'created_by_name' => $this->whenLoaded('createdBy', fn (): ?string => $this->createdBy?->name),
            'reversed_by_transaction_id' => $this->reversed_by_transaction_id !== null ? (int) $this->reversed_by_transaction_id : null,
            'reversed_by' => $this->whenLoaded('reversal', fn (): ?string => $this->reversal?->transaction_number),
            'reversal_numbers' => $this->whenLoaded(
                'reversals',
                fn (): array => $this->reversals->map(fn (FinancialTransaction $r): string => (string) $r->transaction_number)->all(),
            ),
            'metadata' => $this->metadata,
            'lines' => FinancialTransactionLineResource::collection($this->whenLoaded('lines')),
            'lines_count' => $this->whenCounted('lines'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
