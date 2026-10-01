<?php

namespace App\Services\Finance\Accounting;

use App\Models\FinancialTransaction;
use App\Models\FinanceJournalVoidRequest;
use App\Models\User;
use App\Services\Finance\FinancialTransactionEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pembatalan jurnal yang sudah diposting — lewat permohonan, dengan jejak.
 *
 * Jurnal yang sudah diposting **tidak pernah** dihapus atau disunting: koreksinya
 * adalah jurnal pembalik yang menukar debet/kredit dan menyalin dimensinya
 * (unit, dana, pusat biaya) persis, lalu jurnal aslinya ditandai `reversed` dan
 * ditautkan. Dengan begitu pembukuan tetap dapat ditelusuri dua arah.
 *
 * Permohonan ini menambahkan satu lapis yang selama ini hilang: orang yang
 * meminta bukan orang yang menyetujui.
 */
class JournalVoidService
{
    public function __construct(
        private readonly FinancialTransactionEngine $ledger,
    ) {
    }

    public function request(FinancialTransaction $transaction, string $reason, User $actor): FinanceJournalVoidRequest
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan pembatalan wajib diisi.']);
        }

        if ($transaction->status === FinancialTransaction::STATUS_REVERSED) {
            throw ValidationException::withMessages([
                'transaction_id' => "Jurnal {$transaction->transaction_number} sudah pernah dibatalkan.",
            ]);
        }

        $pending = FinanceJournalVoidRequest::query()->where('transaction_id', $transaction->id)->pending()->exists();

        if ($pending) {
            throw ValidationException::withMessages([
                'transaction_id' => "Sudah ada permohonan pembatalan yang menunggu keputusan untuk jurnal {$transaction->transaction_number}.",
            ]);
        }

        return FinanceJournalVoidRequest::query()->create([
            'transaction_id' => $transaction->id,
            'reason' => $reason,
            'status' => 'pending',
            'requested_by' => $actor->id,
        ]);
    }

    /**
     * @param  list<array{account: \App\Models\FinanceCashAccount|\App\Models\FinanceBankAccount, delta: float}>  $balances
     */
    public function decide(FinanceJournalVoidRequest $request, bool $approved, ?User $actor = null, ?string $note = null, array $balances = []): FinanceJournalVoidRequest
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Permohonan ini sudah diputuskan.']);
        }

        if ($actor !== null && (int) $request->requested_by === $actor->id) {
            throw ValidationException::withMessages([
                'decided_by' => 'Permohonan pembatalan jurnal harus diputuskan orang lain, bukan pengaju permohonan itu sendiri.',
            ]);
        }

        return DB::transaction(function () use ($request, $approved, $actor, $note, $balances): FinanceJournalVoidRequest {
            $reversalId = null;

            if ($approved) {
                $transaction = FinancialTransaction::query()->findOrFail($request->transaction_id);
                $reversal = $this->ledger->reverse($transaction, $request->reason, $actor?->id, $balances);
                $reversalId = $reversal->id;
            }

            $request->forceFill([
                'status' => $approved ? 'approved' : 'rejected',
                'decided_by' => $actor?->id,
                'decided_at' => now(),
                'decision_note' => $note,
                'reversal_transaction_id' => $reversalId,
            ])->save();

            return $request->refresh()->load(['transaction:id,transaction_number,description,status', 'decidedBy:id,name']);
        });
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator */
    public function paginate(int $perPage = 25)
    {
        return FinanceJournalVoidRequest::query()
            ->with(['transaction:id,transaction_number,transaction_date,description,total_debit,status', 'requestedBy:id,name', 'decidedBy:id,name'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
