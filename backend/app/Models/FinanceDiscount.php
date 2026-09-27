<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aturan diskon reusable (§17) — nominal atau persen, bisa dibatasi jenis
 * tagihan, program, santri, dan rentang tanggal berlaku.
 */
class FinanceDiscount extends BaseModel
{
    use LogsFinanceActivity;

    public const TYPE_FIXED = 'fixed';

    public const TYPE_PERCENTAGE = 'percentage';

    protected $casts = [
        'value' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FinanceFeeType::class, 'fee_type_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function amountFor(float $base): float
    {
        return $this->type === self::TYPE_PERCENTAGE
            ? round($base * ((float) $this->value / 100), 2)
            : min((float) $this->value, $base);
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_DISCOUNT';
    }
}
