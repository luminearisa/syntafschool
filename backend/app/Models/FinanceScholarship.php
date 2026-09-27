<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Beasiswa (§18) — pengurang tagihan per santri, bisa nominal atau persen,
 * opsional terikat satu jenis tagihan dan rentang tanggal.
 */
class FinanceScholarship extends BaseModel
{
    use LogsFinanceActivity;

    public const TYPE_FIXED = 'fixed';

    public const TYPE_PERCENTAGE = 'percentage';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_EXPIRED,
        self::STATUS_REJECTED, self::STATUS_CANCELLED,
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'percentage' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FinanceFeeType::class, 'fee_type_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** Nominal beasiswa untuk satu tagihan (dipakai billing engine §18). */
    public function amountFor(float $base, ?int $feeTypeId = null): float
    {
        if ($this->fee_type_id !== null && $feeTypeId !== null && (int) $this->fee_type_id !== $feeTypeId) {
            return 0.0;
        }

        return $this->type === self::TYPE_PERCENTAGE
            ? round($base * ((float) $this->percentage / 100), 2)
            : min((float) $this->amount, $base);
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_SCHOLARSHIP';
    }
}
