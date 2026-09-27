<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Chart of Accounts (§5, §6) — satu baris = satu akun, hierarchy lewat parent_id.
 *
 * `normal_balance` menentukan sisi saldo normal (asset/expense = debit;
 * liability/equity/revenue = credit) dan dipakai laporan (trial balance, GL,
 * neraca, laba-rugi). Akun `is_system` berasal dari seeding dan tidak boleh
 * dihapus agar buku besar tetap utuh.
 */
class FinanceAccount extends BaseModel
{
    use LogsFinanceActivity, SoftDeletes;

    public const TYPE_ASSET = 'asset';

    public const TYPE_LIABILITY = 'liability';

    public const TYPE_EQUITY = 'equity';

    public const TYPE_REVENUE = 'revenue';

    public const TYPE_EXPENSE = 'expense';

    public const TYPES = [self::TYPE_ASSET, self::TYPE_LIABILITY, self::TYPE_EQUITY, self::TYPE_REVENUE, self::TYPE_EXPENSE];

    public const TYPE_LABELS = [
        self::TYPE_ASSET => 'Aset',
        self::TYPE_LIABILITY => 'Kewajiban',
        self::TYPE_EQUITY => 'Ekuitas',
        self::TYPE_REVENUE => 'Pendapatan',
        self::TYPE_EXPENSE => 'Pengeluaran',
    ];

    /** Saldo normal bawaan per tipe akun (§47). */
    public const NORMAL_BALANCE_FOR_TYPE = [
        self::TYPE_ASSET => 'debit',
        self::TYPE_EXPENSE => 'debit',
        self::TYPE_LIABILITY => 'credit',
        self::TYPE_EQUITY => 'credit',
        self::TYPE_REVENUE => 'credit',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(FinanceAccount::class, 'parent_id')->orderBy('code');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FinancialTransactionLine::class, 'account_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        return $type ? $query->where('type', $type) : $query;
    }

    /** Akun daun (tanpa anak) — hanya ini yang boleh dipakai transaksi. */
    public function isLeaf(): bool
    {
        return ! $this->children()->exists();
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? (string) $this->type;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_ACCOUNT';
    }
}
