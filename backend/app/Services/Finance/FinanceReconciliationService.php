<?php

namespace App\Services\Finance;

use App\Models\FinanceBankAccount;
use App\Models\FinanceBankStatement;
use App\Models\FinanceBankStatementLine;
use App\Models\FinanceReconciliation;
use App\Models\FinanceReconciliationItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rekonsiliasi bank (§44, §45).
 *
 * Satu header per rekening per tanggal rekening koran. `system_balance` adalah
 * snapshot saldo buku (current_balance rekening) saat rekonsiliasi dibuka;
 * `bank_balance` diisi dari rekening koran. Baris (item) adalah transaksi koran
 * yang dicocokkan ke `finance_bank_transactions` sistem (match_status).
 *
 * `finalize()` menghitung `difference = bank_balance - system_balance`: nol
 * (dalam toleransi sen) → RECONCILED, selain itu → DISCREPANCY yang menuntut
 * adjustment (§51) terpisah. Rekonsiliasi yang sudah final tidak bisa diubah.
 */
class FinanceReconciliationService
{
    private const TOLERANCE = 0.005;

    /**
     * Buka rekonsiliasi (§44). system_balance = saldo buku rekening saat ini.
     *
     * `bank_statement_id` (Phase 4 §4) menautkan rekonsiliasi ke rekening koran
     * yang jadi dasarnya, supaya rantai bukti utuh dan hasil finalisasi bisa
     * dikembalikan ke baris-baris statement asalnya.
     *
     * @param  array{bank_account_id: int, statement_date: Carbon|string, period_start?: Carbon|string|null,
     *               period_end?: Carbon|string|null, bank_balance: float, notes?: string|null,
     *               bank_statement_id?: int|null}  $data
     */
    public function open(array $data, ?User $actor = null): FinanceReconciliation
    {
        $bank = FinanceBankAccount::query()->whereKey((int) $data['bank_account_id'])->first();

        if ($bank === null) {
            throw ValidationException::withMessages(['bank_account_id' => 'Rekening bank tidak ditemukan.']);
        }

        $bankBalance = round((float) ($data['bank_balance'] ?? 0), 2);
        $systemBalance = round((float) $bank->current_balance, 2);

        return FinanceReconciliation::create([
            'bank_account_id' => $bank->id,
            'bank_statement_id' => $data['bank_statement_id'] ?? null,
            'statement_date' => $data['statement_date'],
            'period_start' => $data['period_start'] ?? null,
            'period_end' => $data['period_end'] ?? null,
            'bank_balance' => $bankBalance,
            'system_balance' => $systemBalance,
            'difference' => round($bankBalance - $systemBalance, 2),
            'status' => FinanceReconciliation::STATUS_IN_PROGRESS,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Tambah baris rekonsiliasi (§44). Baris dari rekening koran (Phase 4 §4)
     * membawa `bank_statement_line_id` + `journal_line_id` dan status yang sudah
     * diputuskan di layar review; baris yang diketik tangan dianggap cocok bila
     * `bank_transaction_id` diisi.
     *
     * @param  array{transaction_date?: Carbon|string|null, description?: string|null,
     *               bank_amount: float, bank_transaction_id?: int|null, notes?: string|null,
     *               bank_statement_line_id?: int|null, journal_line_id?: int|null,
     *               match_status?: string|null}  $data
     */
    public function addItem(FinanceReconciliation $reconciliation, array $data, ?User $actor = null): FinanceReconciliationItem
    {
        return DB::transaction(function () use ($reconciliation, $data): FinanceReconciliationItem {
            $fresh = $this->lock($reconciliation);

            $this->assertEditable($fresh);

            $status = $data['match_status'] ?? null;

            if ($status === null) {
                $status = filled($data['bank_transaction_id'] ?? null)
                    ? FinanceReconciliationItem::MATCH_MATCHED
                    : FinanceReconciliationItem::MATCH_UNMATCHED;
            }

            if (! in_array($status, FinanceReconciliationItem::MATCH_STATUSES, true)) {
                throw ValidationException::withMessages(['match_status' => 'Status pencocokan tidak dikenal.']);
            }

            return FinanceReconciliationItem::create([
                'reconciliation_id' => $fresh->id,
                'bank_statement_line_id' => $data['bank_statement_line_id'] ?? null,
                'transaction_date' => $data['transaction_date'] ?? null,
                'description' => $data['description'] ?? null,
                'bank_amount' => round((float) ($data['bank_amount'] ?? 0), 2),
                'bank_transaction_id' => $data['bank_transaction_id'] ?? null,
                'journal_line_id' => $data['journal_line_id'] ?? null,
                'match_status' => $status,
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    /** Ubah status pencocokan sebuah baris (matched/unmatched/ignored/adjusted/reconciled). */
    public function setItemStatus(FinanceReconciliationItem $item, string $status, ?int $bankTransactionId = null): FinanceReconciliationItem
    {
        if (! in_array($status, FinanceReconciliationItem::MATCH_STATUSES, true)) {
            throw ValidationException::withMessages(['match_status' => 'Status pencocokan tidak dikenal.']);
        }

        return DB::transaction(function () use ($item, $status, $bankTransactionId): FinanceReconciliationItem {
            $reconciliation = $this->lock($item->reconciliation);
            $this->assertEditable($reconciliation);

            $fresh = FinanceReconciliationItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $fresh->match_status = $status;

            if ($bankTransactionId !== null) {
                $fresh->bank_transaction_id = $bankTransactionId;
            }

            $fresh->save();

            return $fresh;
        });
    }

    /**
     * Finalkan rekonsiliasi (§45): hitung ulang selisih, tentukan status.
     * Selisih nol → reconciled; selain itu → discrepancy (perlu adjustment §51).
     *
     * Rekonsiliasi yang berdiri di atas rekening koran (Phase 4 §4) ikut
     * menutup sumbernya: hanya bila selisih nol baris statement naik dari
     * MATCHED ke RECONCILED dan header statement ikut direkonsiliasi. Selisih
     * membuat keduanya tetap terbuka — §4 melarang menutup rekonsiliasi yang
     * angkanya belum cocok, dan jalur keluar satu-satunya adalah adjustment
     * resmi (§51), bukan menekan "finalize" dua kali.
     */
    public function finalize(FinanceReconciliation $reconciliation, ?User $actor = null): FinanceReconciliation
    {
        return DB::transaction(function () use ($reconciliation, $actor): FinanceReconciliation {
            $fresh = $this->lock($reconciliation);

            // Finalize menghasilkan dua kemungkinan status (cocok / selisih),
            // jadi yang diuji di sini kelayakan dokumen untuk difinalkan.
            if (! FinanceStatusMachine::can($fresh, FinanceReconciliation::STATUS_RECONCILED)) {
                throw ValidationException::withMessages(['status' => 'Rekonsiliasi sudah difinalkan.']);
            }

            // Segarkan saldo buku bila berubah sejak dibuka.
            $bank = FinanceBankAccount::query()->whereKey($fresh->bank_account_id)->first();
            $systemBalance = round((float) ($bank?->current_balance ?? $fresh->system_balance), 2);
            $bankBalance = round((float) $fresh->bank_balance, 2);
            $difference = round($bankBalance - $systemBalance, 2);

            $fresh->system_balance = $systemBalance;
            $fresh->difference = $difference;
            $fresh->status = abs($difference) <= self::TOLERANCE
                ? FinanceReconciliation::STATUS_RECONCILED
                : FinanceReconciliation::STATUS_DISCREPANCY;
            $fresh->reconciled_by = $actor?->id;
            $fresh->reconciled_at = Carbon::now();
            $fresh->save();

            $this->propagateToStatement($fresh);

            return $fresh;
        });
    }

    // ==================== Internal ====================

    /**
     * Tuliskan hasil finalisasi ke rekening koran sumbernya (§4).
     */
    protected function propagateToStatement(FinanceReconciliation $reconciliation): void
    {
        if ($reconciliation->status !== FinanceReconciliation::STATUS_RECONCILED || $reconciliation->bank_statement_id === null) {
            return;
        }

        $reconciliation->loadMissing('items.bankStatementLine');

        // Header statement dikunci lebih dulu, baru barisnya — urutan parent → child
        // yang sama dengan FinanceBankStatementService. Dibalik, finalize bisa
        // berbarengan dengan pencocokan baris pada statement yang sama: sebagian
        // baris masih `matched` saat header sudah berpindah ke `reconciled` (§5).
        $statement = FinanceBankStatement::query()
            ->whereKey($reconciliation->bank_statement_id)
            ->lockForUpdate()
            ->first();

        if ($statement === null) {
            return;
        }

        $lineIds = $reconciliation->items
            ->where('match_status', '!=', FinanceReconciliationItem::MATCH_IGNORED)
            ->pluck('bank_statement_line_id')
            ->filter()
            ->values();

        if ($lineIds->isNotEmpty()) {
            FinanceBankStatementLine::query()
                ->whereIn('id', $lineIds->all())
                ->where('status', FinanceBankStatementLine::STATUS_MATCHED)
                ->update([
                    'status' => FinanceBankStatementLine::STATUS_RECONCILED,
                    'matched_at' => Carbon::now(),
                ]);
        }

        $reconciliation->items
            ->where('match_status', FinanceReconciliationItem::MATCH_MATCHED)
            ->each(fn (FinanceReconciliationItem $item): mixed => $item->update(['match_status' => FinanceReconciliationItem::MATCH_RECONCILED]));

        if ($statement->status !== FinanceBankStatement::STATUS_CANCELLED) {
            $statement->update(['status' => FinanceBankStatement::STATUS_RECONCILED]);
        }
    }

    protected function assertEditable(FinanceReconciliation $reconciliation): void
    {
        if ($reconciliation->status !== FinanceReconciliation::STATUS_IN_PROGRESS) {
            throw ValidationException::withMessages(['status' => 'Rekonsiliasi yang sudah difinalkan tidak bisa diubah.']);
        }
    }

    protected function lock(FinanceReconciliation $reconciliation): FinanceReconciliation
    {
        return FinanceReconciliation::query()->whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();
    }
}
