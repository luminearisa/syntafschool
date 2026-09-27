<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinancialTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saldo awal kas/bank (§50).
 *
 * Opening balance BUKAN sekadar mengisi kolom `opening_balance`: ia adalah
 * transaksi double-entry (debit kas/bank, credit ekuitas "Saldo Awal") yang
 * diposting lewat ledger engine, supaya neraca hari pertama sudah seimbang dan
 * `current_balance` subledger punya jejak jurnal (§61 — saldo tidak berubah tanpa
 * transaction record).
 *
 * Hanya boleh sekali per akun kas/bank: bila sudah ada opening balance, koreksi
 * harus lewat adjustment, bukan menimpa yang lama.
 */
class FinanceOpeningBalanceService
{
    /** COA ekuitas "Saldo Awal (Opening Balance)" dari seed (§5). */
    public const EQUITY_ACCOUNT_CODE = '3100';

    public function __construct(
        private readonly FinancialTransactionEngine $ledger,
    ) {}

    /**
     * Set saldo awal satu akun kas atau bank. `date` harus jatuh di periode open.
     */
    public function set(
        FinanceCashAccount|FinanceBankAccount $account,
        float $amount,
        ?Carbon $date = null,
        ?User $actor = null,
        ?string $note = null,
    ): FinancialTransaction {
        if (! is_finite($amount) || round($amount, 2) < 0) {
            throw ValidationException::withMessages(['amount' => 'Saldo awal tidak boleh negatif.']);
        }

        return DB::transaction(function () use ($account, $amount, $date, $actor, $note): FinancialTransaction {
            // Kunci akun dulu agar dua pembukaan bersamaan tidak lolos ganda.
            $locked = $account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

            if ((float) $locked->opening_balance !== 0.0 || (float) $locked->current_balance !== 0.0) {
                throw ValidationException::withMessages([
                    'amount' => 'Saldo awal sudah pernah diset untuk akun ini. Gunakan adjustment untuk mengoreksi.',
                ]);
            }

            $equity = FinanceAccount::query()->where('code', self::EQUITY_ACCOUNT_CODE)->firstOrFail();
            $when = $date ?? Carbon::now();

            // Nominal nol tidak butuh jurnal; cukup tandai opening 0.
            if (round($amount, 2) === 0.0) {
                $locked->opening_balance = 0;
                $locked->save();

                throw ValidationException::withMessages([
                    'amount' => 'Saldo awal harus lebih besar dari nol untuk diposting.',
                ]);
            }

            $transaction = $this->ledger->openingBalance(
                $locked,
                $equity->id,
                $amount,
                $note ?: 'Saldo awal '.$locked->name,
                ['date' => $when, 'created_by' => $actor?->id, 'cost_center_id' => $locked->cost_center_id],
            );

            $locked->opening_balance = round($amount, 2);
            $locked->save();

            return $transaction;
        });
    }
}
