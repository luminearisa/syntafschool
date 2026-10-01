<?php

namespace App\Modules\Bk\Models;

use App\Models\Student;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak evaluasi mesin aturan.
 *
 * Setiap evaluasi tercatat, termasuk yang tidak cocok dan yang ditahan jeda,
 * sehingga pertanyaan kenapa tidak ada peringatan punya jawaban yang dapat
 * diperiksa.
 *
 * Tabel: `bk_early_warning_events`
 */
class BkEarlyWarningEvent extends BkModel
{
    protected $table = 'bk_early_warning_events';

    use BelongsToSchool;

    protected $casts = [
        'observed_value' => 'decimal:4',
        'threshold' => 'decimal:4',
        'period_start' => 'date',
        'period_end' => 'date',
        'evaluation_date' => 'date',
        'matched' => 'boolean',
        'actioned_at' => 'datetime',
        'context' => 'array',
        'metric' => \App\Modules\Bk\Domain\Enums\EarlyWarningMetric::class,
        'status' => \App\Modules\Bk\Domain\Enums\EarlyWarningEventStatus::class,
        'action_taken' => \App\Modules\Bk\Domain\Enums\EarlyWarningAction::class,
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(BkEarlyWarningRule::class, 'rule_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(BkRiskIndicator::class, 'indicator_id');
    }

    public function scopeMatched(Builder $query): Builder
    {
        return $query->where('matched', true);
    }
}
