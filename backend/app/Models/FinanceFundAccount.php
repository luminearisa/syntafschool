<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daftar akun yang boleh dibebani sebuah dana.
 *
 * Dana tanpa satu pun baris di sini berarti TIDAK membatasi akun — bukan
 * melarang semuanya. Perbedaan itu penting: bila dana kosong dianggap larangan,
 * setiap dana baru langsung menolak seluruh jurnal dan pengguna akan belajar
 * berhenti menandai dana sama sekali.
 *
 * Tabel: `finance_fund_accounts`
 */
class FinanceFundAccount extends BaseModel
{
    protected $table = 'finance_fund_accounts';

    protected $fillable = ['kode_dana', 'account_id'];

    public function fund(): BelongsTo
    {
        return $this->belongsTo(FinanceFund::class, 'kode_dana', 'kode_dana');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }
}
