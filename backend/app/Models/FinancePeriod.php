<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Periode fiskal (§48) dengan status open/closed (§49).
 *
 * Ledger engine menolak memposting transaksi ke periode closed; koreksi harus
 * lewat adjustment/reversal, bukan mengedit transaksi lama.
 */
class FinancePeriod extends BaseModel
{
    use LogsFinanceActivity;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_CLOSED];

    public const TYPE_YEARLY = 'yearly';

    public const TYPE_MONTHLY = 'monthly';

    protected $casts = [
        'fiscal_year' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'closed_at' => 'datetime',
    ];

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function transactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FinancialTransaction::class, 'period_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function contains(?Carbon $date): bool
    {
        if ($date === null) {
            return false;
        }

        return $date->betweenIncluded($this->start_date, $this->end_date);
    }

    /** Periode yang memuat tanggal tertentu (untuk penempatan transaksi). */
    public static function forDate(?Carbon $date): ?self
    {
        if ($date === null) {
            return null;
        }

        return static::query()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->orderByDesc('id')
            ->first();
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_PERIOD';
    }
}
