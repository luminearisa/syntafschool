<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrOrganization;
use App\Modules\Hrd\Strategic\Governance\Models\HrRisk;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrRiskSignal extends Model
{
    protected $table = 'hr_risk_signals';

    protected $guarded = [];

    protected $casts = [
        'risk_id' => 'integer',
        'code' => 'string',
        'signal_type' => 'string',
        'severity' => 'string',
        'signal_value' => 'decimal:2',
        'threshold' => 'decimal:2',
        'evidence' => 'array',
        'period' => 'string',
        'subject_type' => 'string',
        'subject_id' => 'integer',
        'employee_id' => 'integer',
        'organization_id' => 'integer',
        'recommended_action' => 'string',
        'detected_at' => 'datetime',
        'status' => 'string',
        'dedupe_key' => 'string',
        'meta' => 'array',
    ];

    public function risk(): BelongsTo
    {
        return $this->belongsTo(HrRisk::class, 'risk_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }
}
