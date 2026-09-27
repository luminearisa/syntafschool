<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris jurnal (§5, §47): satu akun + debit + credit. Total debit dan credit
 * dalam satu `FinancialTransaction` wajib seimbang.
 *
 * `cost_center_id` per baris (bukan hanya per header) supaya invoice multi-item
 * lintas unit — SPP sekolah + asrama — tetap teralokasi benar di laporan §42.
 *
 * `subledger_type`/`subledger_id` (bisa null) menunjuk rekening kas/bank konkret
 * yang digerakkan baris ini. Baris tetap mencatat COA control account (§1), jadi
 * kolom inilah yang membuat saldo cached terbukti dari buku besar, bukan dipercaya
 * apa adanya. Null berarti baris tidak menunjuk satu rekening tunggal (mis. satu
 * baris debit untuk gabungan beberapa laci) — lihat catatan di engine.
 */
class FinancialTransactionLine extends BaseModel
{
    // Tabel hasil migrasi bernama "finance_transaction_lines", bukan pluralisasi
    // default kelas "FinancialTransactionLine".
    protected $table = 'finance_transaction_lines';

    public const SUBLEDGER_CASH = 'cash_account';

    public const SUBLEDGER_BANK = 'bank_account';

    public const SUBLEDGER_TYPES = [
        self::SUBLEDGER_CASH => FinanceCashAccount::class,
        self::SUBLEDGER_BANK => FinanceBankAccount::class,
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'finance_transaction_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(FinanceCostCenter::class, 'cost_center_id');
    }

    /** Alias `subledger_type` untuk rekening, dipakai stempel engine. */
    public static function subledgerTypeForAccount(FinanceCashAccount|FinanceBankAccount $account): string
    {
        return $account instanceof FinanceBankAccount ? self::SUBLEDGER_BANK : self::SUBLEDGER_CASH;
    }

    /** Kelas model untuk alias `subledger_type`, atau null bila alias tak dikenal. */
    public static function subledgerModelClass(string|null $type): string|null
    {
        return self::SUBLEDGER_TYPES[$type] ?? null;
    }
}
