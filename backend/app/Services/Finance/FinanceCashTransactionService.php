<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceCashierSession;
use App\Models\FinanceCashTransaction;
use App\Models\FinancialTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transaksi kas standalone (Phase 4 §2) — mutasi uang yang tidak lahir dari
 * dokumen lain: pengisian kas kecil, setoran penerimaan lain ke kas besar.
 *
 * Kontrak yang dijaga di sini: setiap baris yang dibuat SELALU menghasilkan
 * jurnal lewat {@see FinancialTransactionEngine}. Tidak ada jalur yang
 * menulis `current_balance` langsung, jadi "transaksi kas tanpa journal" (§2)
 * secara struktural tidak bisa terjadi — dan saldo rekening tetap bisa
 * direkonstruksi dari buku besar (baris jurnalnya distempel rekeningnya).
 *
 * Akun lawan wajib diisi dan tidak boleh rekening uang (kas/bank lain): memindah
 * uang antar rekening adalah transfer dan punya workflow sendiri (§3). Mencegah
 * kas-in/kas-out dipakai diam-diam sebagai jalur pintas transfer adalah alasan
 * kolom ini ada — kalau tidak, satu perpindahan uang bisa tercatat dua kali.
 */
class FinanceCashTransactionService
{
    public function __construct(
        private readonly FinancialTransactionEngine $ledger,
        private readonly FinanceNumberingService $numbering,
    ) {}

    /**
     * Catat satu transaksi kas + posting jurnalnya dalam satu transaksi DB.
     *
     * @param  array{cash_account_id: int, type: string, amount: float, contra_account_id: int,
     *               transaction_date?: Carbon|string|null, reference?: string|null,
     *               description?: string|null, cashier_session_id?: int|null}  $data
     */
    public function record(array $data, ?User $actor = null): FinanceCashTransaction
    {
        $type = (string) ($data['type'] ?? '');

        if (! in_array($type, FinanceCashTransaction::TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => 'Tipe transaksi kas harus cash_in atau cash_out.',
            ]);
        }

        $amount = round((float) ($data['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal transaksi kas harus lebih dari nol.']);
        }

        $contra = $this->resolveContra((int) ($data['contra_account_id'] ?? 0));
        $inflow = $type === FinanceCashTransaction::TYPE_CASH_IN;

        return DB::transaction(function () use ($data, $type, $amount, $contra, $inflow, $actor): FinanceCashTransaction {
            $cash = FinanceCashAccount::query()->whereKey((int) $data['cash_account_id'])->lockForUpdate()->firstOrFail();

            if (! $cash->is_active) {
                throw ValidationException::withMessages(['cash_account_id' => "Kas {$cash->name} tidak aktif."]);
            }

            $session = $this->resolveSession($data['cashier_session_id'] ?? null, $cash);
            $date = $data['transaction_date'] ?? Carbon::now()->toDateString();
            $description = trim((string) ($data['description'] ?? '')) ?: ($inflow ? 'Kas masuk' : 'Kas keluar').' '.$cash->name;

            $journal = $inflow
                ? $this->ledger->cashReceipt($cash, $contra->id, $amount, $description, $this->context($date, $actor, $contra))
                : $this->ledger->cashDisbursement($cash, $contra->id, $amount, $description, $this->context($date, $actor, $contra));

            $transaction = FinanceCashTransaction::create([
                'transaction_number' => $this->numbering->next(FinanceNumberingService::CODE_CASH_TRANSACTION),
                'cash_account_id' => $cash->id,
                'transaction_date' => $date,
                'type' => $type,
                'amount' => $amount,
                'contra_account_id' => $contra->id,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'cashier_session_id' => $session?->id,
                'finance_transaction_id' => $journal->id,
                'created_by' => $actor?->id,
            ]);

            // Jurnal menunjuk dokumen sumbernya (§3: setiap transaksi punya reference
            // yang jelas) — penelusuran §38 membacanya dari arah ini.
            $journal->update(['reference_id' => $transaction->id]);

            return $transaction;
        });
    }

    /**
     * Context jurnal. Tipe jurnal diikuti dari akun lawan, bukan dari arah uangnya:
     * kas masuk yang lawannya akun pendapatan memang pendapatan; kas masuk karena
     * titipan/dana diterima bukan pendapatan (§27: payment ≠ revenue).
     *
     * @return array<string, mixed>
     */
    private function context(string $date, ?User $actor, FinanceAccount $contra): array
    {
        $isRevenueSide = $contra->type === FinanceAccount::TYPE_REVENUE;
        $isExpenseSide = $contra->type === FinanceAccount::TYPE_EXPENSE;

        return [
            'date' => $date,
            'type' => match (true) {
                $isRevenueSide => FinancialTransaction::TYPE_REVENUE,
                $isExpenseSide => FinancialTransaction::TYPE_EXPENSE,
                default => FinancialTransaction::TYPE_PAYMENT,
            },
            'reference_type' => FinanceCashTransaction::class,
            'created_by' => $actor?->id,
        ];
    }

    /** Akun lawan: daun, aktif, dan bukan rekening uang (§3 memilikinya). */
    private function resolveContra(int $accountId): FinanceAccount
    {
        $account = FinanceAccount::query()->whereKey($accountId)->first();

        if ($account === null) {
            throw ValidationException::withMessages(['contra_account_id' => 'Akun lawan (contra) tidak ditemukan.']);
        }

        if (! $account->isLeaf() || ! $account->is_active) {
            throw ValidationException::withMessages(['contra_account_id' => "Akun lawan {$account->code} tidak bisa dipakai (induk/nonaktif)."]);
        }

        $coversMoney = FinanceCashAccount::query()->where('account_id', $account->id)->exists()
            || FinanceBankAccount::query()->where('finance_account_id', $account->id)->exists();

        if ($coversMoney) {
            throw ValidationException::withMessages([
                'contra_account_id' => "Akun {$account->code} adalah rekening kas/bank. Pindahkan uang antar rekening lewat /finance/transfers (§3), bukan sebagai kas masuk/keluar — kalau tidak, satu perpindahan uang tercatat dua kali dan tidak lagi bisa direkonsiliasi.",
            ]);
        }

        return $account;
    }

    /** Sesi kasir yang sedang terbuka pada kas yang sama, bila disebutkan. */
    private function resolveSession(?int $sessionId, FinanceCashAccount $cash): ?FinanceCashierSession
    {
        if ($sessionId === null) {
            return null;
        }

        $session = FinanceCashierSession::query()->whereKey($sessionId)->first();

        if ($session === null) {
            throw ValidationException::withMessages(['cashier_session_id' => 'Sesi kasir tidak ditemukan.']);
        }

        if ((int) $session->cash_account_id !== (int) $cash->id) {
            throw ValidationException::withMessages([
                'cashier_session_id' => 'Sesi kasir terbuka untuk rekening lain, bukan kas ini.',
            ]);
        }

        if ($session->status !== FinanceCashierSession::STATUS_OPEN) {
            throw ValidationException::withMessages(['cashier_session_id' => 'Sesi kasir sudah ditutup.']);
        }

        return $session;
    }
}
