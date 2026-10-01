<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo awal per akun.
 *
 * Sampai `posted`, ia hanya rancangan yang boleh diubah; begitu diposting, ia
 * menjadi jurnal pembuka yang terkunci dan tidak dapat disunting lagi —
 * koreksinya lewat jurnal pembalik, seperti transaksi lain.
 *
 * Tabel: `finance_opening_balances`
 */
class FinanceOpeningBalance extends BaseModel
{
    protected $table = 'finance_opening_balances';

    protected $casts = [
        'saldo' => 'decimal:2',
        'posted' => 'boolean',
        'posted_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'transaction_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('posted', true);
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('posted', false);
    }
}
