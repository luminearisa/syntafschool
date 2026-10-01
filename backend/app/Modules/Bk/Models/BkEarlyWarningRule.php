<?php

namespace App\Modules\Bk\Models;

use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Aturan peringatan dini.
 *
 * Ambang, jendela waktu, dan tindakan adalah data, bukan konstanta di kode,
 * sehingga kebijakan sekolah dapat berubah tanpa deploy dan setiap perubahan
 * meninggalkan versi yang dapat ditelusuri.
 *
 * Tabel: `bk_early_warning_rules`
 */
class BkEarlyWarningRule extends BkModel
{
    protected $table = 'bk_early_warning_rules';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'threshold' => 'decimal:4',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'requires_review' => 'boolean',
        'action_params' => 'array',
        'scope' => 'array',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'metric' => \App\Modules\Bk\Domain\Enums\EarlyWarningMetric::class,
        'operator' => \App\Modules\Bk\Domain\Enums\EarlyWarningOperator::class,
        'action' => \App\Modules\Bk\Domain\Enums\EarlyWarningAction::class,
        'indicator_code' => \App\Modules\Bk\Domain\Enums\RiskIndicatorCode::class,
        'severity' => \App\Modules\Bk\Domain\Enums\RiskSeverity::class,
    ];

    public function events(): HasMany
    {
        return $this->hasMany(BkEarlyWarningEvent::class, 'rule_id');
    }

    public function indicators(): HasMany
    {
        return $this->hasMany(BkRiskIndicator::class, 'rule_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $inner): Builder => $inner
                ->whereNull('effective_from')->orWhere('effective_from', '<=', today()))
            ->where(fn (Builder $inner): Builder => $inner
                ->whereNull('effective_to')->orWhere('effective_to', '>=', today()));
    }

    /** Apakah aturan ini berlaku untuk siswa dengan konteks tertentu? */
    public function appliesTo(array $studentContext): bool
    {
        $scope = $this->scope ?? [];

        foreach (['grades' => 'grade', 'class_ids' => 'class_id', 'genders' => 'gender'] as $key => $contextKey) {
            $allowed = $scope[$key] ?? [];

            if (! empty($allowed) && ! in_array($studentContext[$contextKey] ?? null, $allowed, true)) {
                return false;
            }
        }

        return true;
    }
}
