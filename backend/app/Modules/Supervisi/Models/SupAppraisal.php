<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use App\Modules\Supervisi\Domain\Enums\AppraisalPeriodType;
use App\Modules\Supervisi\Domain\Enums\AppraisalStatus;
use App\Modules\Supervisi\Domain\Enums\AppraisalType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Employee;

/**
 * Dokumen penilaian kinerja: PKG, PKG BK, NKKS, tinjauan pegawai, SKP periodik.
 *
 * Seluruh angka (nilai akhir, sebutan, % angka kredit, koreksi kehadiran)
 * dihitung `AppraisalCalculator` dan disimpan bersama `snapshot` rumusnya.
 */
class SupAppraisal extends BaseModel
{
    use SoftDeletes;

    protected $table = 'sup_appraisals';

    protected $casts = [
        'appraisal_type' => AppraisalType::class,
        'period_type' => AppraisalPeriodType::class,
        'status' => AppraisalStatus::class,
        'period_start' => 'date',
        'period_end' => 'date',
        'raw_score' => 'float',
        'max_score' => 'float',
        'final_score' => 'float',
        'credit_percent' => 'float',
        'attendance_absence_days' => 'integer',
        'attendance_correction_percent' => 'float',
        'supervision_weight' => 'float',
        'supervision_contribution' => 'float',
        'snapshot' => 'array',
        'published_at' => 'datetime',
    ];

    public function supervisee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisee_employee_id');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assessor_employee_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewer_employee_id');
    }

    public function instrumentVersion(): BelongsTo
    {
        return $this->belongsTo(SupInstrumentVersion::class, 'instrument_version_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(SupAppraisalScore::class, 'appraisal_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(SupAppraisalSource::class, 'appraisal_id');
    }
}
