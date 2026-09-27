<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Jenis tagihan (§10) — SPP, Uang Gedung, Makan, Boarding, dst.
 *
 * `account_id` memetakan jenis tagihan ke akun PENDAPATAN (COA) dan
 * `receivable_account_id` ke akun PIUTANG-nya (§20). Keduanya boleh berbeda per
 * jenis — pendapatan SPP masuk 4100 sementara piutang SPP mengikuti 1300 — dan
 * kalau kolom itu kosong policy accounting jatuh ke akun default modul, bukan
 * nilai yang di-hard-code di service.
 */
class FinanceFeeType extends BaseModel
{
    use LogsFinanceActivity, SoftDeletes;

    public const FREQ_ONCE = 'once';

    public const FREQ_MONTHLY = 'monthly';

    public const FREQ_SEMESTERLY = 'semesterly';

    public const FREQ_YEARLY = 'yearly';

    public const FREQ_CUSTOM = 'custom';

    public const FREQUENCIES = [
        self::FREQ_ONCE, self::FREQ_MONTHLY, self::FREQ_SEMESTERLY, self::FREQ_YEARLY, self::FREQ_CUSTOM,
    ];

    public const FREQUENCY_LABELS = [
        self::FREQ_ONCE => 'Sekali',
        self::FREQ_MONTHLY => 'Bulanan',
        self::FREQ_SEMESTERLY => 'Semesteran',
        self::FREQ_YEARLY => 'Tahunan',
        self::FREQ_CUSTOM => 'Kustom',
    ];

    protected $casts = [
        'default_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }

    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'receivable_account_id');
    }

    public function packageItems(): HasMany
    {
        return $this->hasMany(FinanceFeeItem::class, 'fee_type_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function frequencyLabel(): string
    {
        return self::FREQUENCY_LABELS[$this->frequency] ?? (string) $this->frequency;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_FEE_TYPE';
    }
}
