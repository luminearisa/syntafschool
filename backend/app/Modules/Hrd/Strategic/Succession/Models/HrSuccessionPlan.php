<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Succession\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrOrganization;
use App\Modules\Hrd\Models\HrPosition;
use App\Modules\Hrd\Strategic\Succession\Models\HrCriticalPosition;
use App\Modules\Hrd\Strategic\Succession\Models\HrSuccessionCandidate;
use App\Modules\Hrd\Strategic\Succession\Models\HrSuccessionReadiness;
use App\Modules\Hrd\Strategic\Succession\Models\HrSuccessionRisk;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrSuccessionPlan extends Model
{
    protected $table = 'hr_succession_plans';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'critical_position_id' => 'integer',
        'position_id' => 'integer',
        'organization_id' => 'integer',
        'plan_type' => 'string',
        'status' => 'string',
        'coverage_status' => 'string',
        'horizon_months' => 'integer',
        'owner_employee_id' => 'integer',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'notes' => 'string',
        'meta' => 'array',
    ];

    public function criticalPosition(): BelongsTo
    {
        return $this->belongsTo(HrCriticalPosition::class, 'critical_position_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'owner_employee_id');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(HrSuccessionCandidate::class, 'plan_id');
    }

    public function readiness(): HasMany
    {
        return $this->hasMany(HrSuccessionReadiness::class, 'plan_id');
    }

    public function risks(): HasMany
    {
        return $this->hasMany(HrSuccessionRisk::class, 'plan_id');
    }
}
