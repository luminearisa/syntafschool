<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceBankAccount;
use App\Models\FinanceBankTransaction;
use App\Models\FinancialTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transaksi bank (§41) — deposit/withdrawal/fee/adjustment yang menggerakkan
 * saldo rekening lewat ledger engine (double-entry tetap terjaga §47).
 *
 * Setiap pergerakan uang wajib punya akun lawan (contra) COA agar jurnal
 * seimbang: deposit → Dr Bank, Cr contra; withdrawal/fee → Dr contra, Cr Bank.
 * Baris bertipe `transfer` tidak dibuat di sini melainkan oleh
 * {@see FinanceTransferService} (jurnalnya milik transfer), jadi `record()`
 * menolaknya agar tidak ada dua sumber yang memindahkan saldo yang sama.
 */
class FinanceBankTransactionService
{
    public function __construct(
        private readonly FinancialTransactionEngine $ledger,
    ) {}

    /**
     * Catat transaksi bank standalone (deposit/withdrawal/fee) + posting jurnal.
     *
     * @param  array{bank_account_id: int, transaction_date?: Carbon|string|null, type: string,
     *               amount: float, contra_account_id: int, reference?: string|null,
     *               description?: string|null}  $data
     */
    public function record(array $data, ?User $actor = null): FinanceBankTransaction
    {
        $type = (string) ($data['type'] ?? '');

        if (! in_array($type, [FinanceBankTransaction::TYPE_DEPOSIT, FinanceBankTransaction::TYPE_WITHDRAWAL, FinanceBankTransaction::TYPE_FEE], true)) {
            throw ValidationException::withMessages([
                'type' => 'Tipe transaksi bank standalone harus deposit, withdrawal, atau fee.',
            ]);
        }

        $amount = round((float) ($data['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal transaksi bank harus lebih dari nol.']);
        }

        $contraId = (int) ($data['contra_account_id'] ?? 0);
        $this->assertContraAccount($contraId);

        return DB::transaction(function () use ($data, $type, $amount, $contraId, $actor): FinanceBankTransaction {
            $bank = FinanceBankAccount::query()->whereKey((int) $data['bank_account_id'])->lockForUpdate()->firstOrFail();

            $description = (string) ($data['description'] ?? '');
            $date = $data['transaction_date'] ?? Carbon::now()->toDateString();

            if ($type === FinanceBankTransaction::TYPE_DEPOSIT) {
                $journal = $this->ledger->cashReceipt($bank, $contraId, $amount, $description !== '' ? $description : 'Setoran bank', [
                    'type' => FinancialTransaction::TYPE_REVENUE,
                    'date' => $date,
                    'reference_type' => FinanceBankTransaction::class,
                    'created_by' => $actor?->id,
                ]);
            } else {
                $journal = $this->ledger->cashDisbursement($bank, $contraId, $amount, $description !== '' ? $description : 'Penarikan/biaya bank', [
                    'type' => FinancialTransaction::TYPE_EXPENSE,
                    'date' => $date,
                    'reference_type' => FinanceBankTransaction::class,
                    'created_by' => $actor?->id,
                ]);
            }

            $transaction = FinanceBankTransaction::create([
                'bank_account_id' => $bank->id,
                'transaction_date' => $date,
                'type' => $type,
                'amount' => $amount,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'finance_transaction_id' => $journal->id,
                'created_by' => $actor?->id,
            ]);

            // Tautkan balik jurnal ke baris transaksi bank (reference_id).
            $journal->update(['reference_id' => $transaction->id]);

            return $transaction;
        });
    }

    /**
     * Buat baris transaksi bank bertipe `transfer` yang menaut ke sebuah transfer
     * internal. Dipanggil {@see FinanceTransferService}; tidak memposting jurnal
     * sendiri (jurnal transfer yang menggerakkan saldo).
     */
    public function recordTransferLeg(FinanceBankAccount $bank, float $amount, int $transferId, string $direction, ?User $actor = null): FinanceBankTransaction
    {
        return FinanceBankTransaction::create([
            'bank_account_id' => $bank->id,
            'transaction_date' => Carbon::now()->toDateString(),
            'type' => FinanceBankTransaction::TYPE_TRANSFER,
            'amount' => round(abs($amount), 2),
            'reference' => $direction === 'in' ? 'Transfer masuk' : 'Transfer keluar',
            'description' => 'Transfer internal #'.$transferId,
            'transfer_id' => $transferId,
            'created_by' => $actor?->id,
        ]);
    }

    protected function assertContraAccount(int $accountId): void
    {
        $account = FinanceAccount::query()->whereKey($accountId)->first();

        if ($account === null) {
            throw ValidationException::withMessages(['contra_account_id' => 'Akun lawan (contra) tidak ditemukan.']);
        }

        if (! $account->isLeaf() || ! $account->is_active) {
            throw ValidationException::withMessages(['contra_account_id' => "Akun lawan {$account->code} tidak bisa dipakai (induk/nonaktif)."]);
        }
    }
}
