<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Rekening bank (§40). `bank_id` menunjuk master `banks` existing (BSI/BCA/…)
 * — tidak membuat master bank baru. `finance_account_id` adalah akun COA bank
 * yang saldonya digerakkan ledger engine.
 */
class FinanceBankAccount extends BaseModel
{
    use LogsFinanceActivity, SoftDeletes;

    public const TYPE_OPERATIONAL = 'operational';

    public const TYPE_PAYROLL = 'payroll';

    public const TYPE_SAVINGS = 'savings';

    public const TYPE_OTHER = 'other';

    public const TYPES = [self::TYPE_OPERATIONAL, self::TYPE_PAYROLL, self::TYPE_SAVINGS, self::TYPE_OTHER];

    public const TYPE_LABELS = [
        self::TYPE_OPERATIONAL => 'Operasional',
        self::TYPE_PAYROLL => 'Penggajian',
        self::TYPE_SAVINGS => 'Tabungan',
        self::TYPE_OTHER => 'Lainnya',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    public function financeAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'finance_account_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(FinanceCostCenter::class, 'cost_center_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinanceBankTransaction::class, 'bank_account_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_BANK_ACCOUNT';
    }
}
