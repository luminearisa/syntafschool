<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Akrual (jurnal penyesuaian) beserta tanggal baliknya.
 *
 * Akrual selalu lahir dengan rencana pembalikan: pendapatan/beban yang diakui di
 * bulan ini dan dibalik di bulan berikutnya, sehingga pengakuan tidak bergantung
 * pada siapa yang ingat membalikkannya.
 *
 * Tabel: `finance_accruals`
 */
class FinanceAccrual extends BaseModel
{
    protected $table = 'finance_accruals';

    protected $fillable = [
        'kode', 'tanggal', 'tanggal_balik', 'jenis', 'akun_akrual_id', 'akun_lawan_id', 'jumlah',
        'deskripsi', 'status', 'transaction_id', 'reversal_transaction_id', 'kode_unit', 'kode_dana',
        'created_by',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'tanggal_balik' => 'date',
        'jumlah' => 'decimal:2',
    ];

    public function akunAkrual(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'akun_akrual_id');
    }

    public function akunLawan(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'akun_lawan_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'transaction_id');
    }

    public function reversalTransaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'reversal_transaction_id');
    }

    public function scopeDueForReversal(Builder $query): Builder
    {
        return $query->where('status', 'posted')->whereDate('tanggal_balik', '<=', now()->toDateString());
    }
}
