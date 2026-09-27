<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cost center (§7) — unit (Sekolah, Pesantren, Boarding, Tahfidz, Kitchen, …)
 * untuk mengukur pendapatan, pengeluaran, dan budget per unit.
 */
class FinanceCostCenter extends BaseModel
{
    use LogsFinanceActivity, SoftDeletes;

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(FinanceCostCenter::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(FinanceCostCenter::class, 'parent_id')->orderBy('code');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(FinanceBudget::class, 'cost_center_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_COST_CENTER';
    }
}
