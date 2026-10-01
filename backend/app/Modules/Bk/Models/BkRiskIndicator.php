<?php

namespace App\Modules\Bk\Models;

use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Penanda dukungan/risiko siswa.
 *
 * Penanda menyebut pengamatan yang dapat diverifikasi, bukan diagnosis. Ia meminta
 * manusia meninjau, dan itulah seluruh maksudnya.
 *
 * Tabel: `bk_risk_indicators`
 */
class BkRiskIndicator extends BkModel
{
    protected $table = 'bk_risk_indicators';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'detected_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'period_start' => 'date',
        'period_end' => 'date',
        'evidence' => 'array',
        'observed_value' => 'decimal:4',
        'threshold_value' => 'decimal:4',
        'indicator_type' => \App\Modules\Bk\Domain\Enums\RiskIndicatorType::class,
        'indicator_code' => \App\Modules\Bk\Domain\Enums\RiskIndicatorCode::class,
        'severity' => \App\Modules\Bk\Domain\Enums\RiskSeverity::class,
        'source_module' => \App\Modules\Bk\Domain\Enums\RiskSourceModule::class,
        'status' => \App\Modules\Bk\Domain\Enums\RiskIndicatorStatus::class,
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(BkEarlyWarningRule::class, 'rule_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(BkEarlyWarningEvent::class, 'event_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(BkReferral::class, 'referral_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['OPEN', 'ACKNOWLEDGED', 'UNDER_REVIEW']);
    }
}
