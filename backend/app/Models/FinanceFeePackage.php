<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Paket tagihan (§11, §12) — sekelompok jenis tagihan untuk segmen tertentu
 * (program/jenjang/kelas/boarding/tahun ajaran). Total dihitung dari itemnya.
 */
class FinanceFeePackage extends BaseModel
{
    use LogsFinanceActivity, SoftDeletes;

    public const BOARDING = 'boarding';

    public const NON_BOARDING = 'non_boarding';

    public const ALL = 'all';

    public const BOARDING_TYPES = [self::BOARDING, self::NON_BOARDING, self::ALL];

    protected $casts = [
        'academic_year' => 'integer',
        'is_active' => 'boolean',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FinanceFeeItem::class, 'fee_package_id');
    }

    public function activeItems(): HasMany
    {
        return $this->items()->where('is_active', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function total(): float
    {
        return (float) $this->activeItems()->get()->sum(fn (FinanceFeeItem $item): float => (float) $item->amount);
    }

    /**
     * Frekuensi default untuk satu jenis tagihan saat baris paket tidak
     * menyatakannya: mengikuti frekuensi jenis tagihan itu (§10), fallback bulanan.
     */
    public function defaultFrequencyFor(int $feeTypeId): string
    {
        $frequency = FinanceFeeType::query()->whereKey($feeTypeId)->value('frequency');

        return is_string($frequency) && $frequency !== '' ? $frequency : FinanceFeeType::FREQ_MONTHLY;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_FEE_PACKAGE';
    }
}
