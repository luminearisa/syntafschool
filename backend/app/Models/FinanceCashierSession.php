<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sesi kasir (§22, §23) — payment tunai wajib punya sesi terbuka. Saat tutup:
 * hitung expected (opening + penerimaan), bandingkan actual, selisih WAJIB
 * punya reason, lalu diajukan untuk review finance.
 */
class FinanceCashierSession extends BaseModel
{
    use LogsFinanceActivity;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_CLOSED, self::STATUS_REVIEWED];

    public const STATUS_LABELS = [
        self::STATUS_OPEN => 'Terbuka',
        self::STATUS_CLOSED => 'Ditutup',
        self::STATUS_REVIEWED => 'Direview',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'opening_cash' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'actual_cash' => 'decimal:2',
        'difference' => 'decimal:2',
    ];

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceCashAccount::class, 'cash_account_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FinancePayment::class, 'cashier_session_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_CASHIER_SESSION';
    }
}
