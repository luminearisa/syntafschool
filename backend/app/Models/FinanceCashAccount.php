<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Akun kas (§39) — Kas Utama, Kas Boarding, Kas Kantin, Kas Kecil.
 *
 * `current_balance` digerakkan HANYA oleh ledger engine saat transaksi kas
 * diposting/dibalik; tidak boleh diedit langsung (§61).
 */
class FinanceCashAccount extends BaseModel
{
    use LogsFinanceActivity, SoftDeletes;

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(FinanceCostCenter::class, 'cost_center_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_CASH_ACCOUNT';
    }
}
