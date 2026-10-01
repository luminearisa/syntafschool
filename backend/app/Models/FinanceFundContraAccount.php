<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Akun pengurang dana bebas.
 *
 * Dipakai ketika dana bebas membiayai hal yang seharusnya dibiayai dana terikat
 * (mis. beasiswa dari kas yayasan). Saldo akun ini mengurangi dana bebas pada
 * laporan dana, sehingga beban bersama tidak membuat dana bebas terlihat lebih
 * besar daripada kenyataannya.
 *
 * Tabel: `finance_fund_contra_accounts`
 */
class FinanceFundContraAccount extends BaseModel
{
    protected $table = 'finance_fund_contra_accounts';

    protected $fillable = ['kode_dana', 'account_id', 'keterangan'];

    public function fund(): BelongsTo
    {
        return $this->belongsTo(FinanceFund::class, 'kode_dana', 'kode_dana');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }
}
