<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrOrganization;
use App\Modules\Hrd\Strategic\Governance\Models\HrRiskAction;
use App\Modules\Hrd\Strategic\Governance\Models\HrRiskHistory;
use App\Modules\Hrd\Strategic\Governance\Models\HrRiskSignal;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrRisk extends Model
{
    protected $table = 'hr_risks';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'title' => 'string',
        'risk_type' => 'string',
        'category' => 'string',
        'description' => 'string',
        'scope_organization_id' => 'integer',
        'severity' => 'string',
        'likelihood' => 'string',
        'impact_score' => 'decimal:2',
        'risk_score' => 'decimal:2',
        'period' => 'string',
        'owner_employee_id' => 'integer',
        'status' => 'string',
        'source' => 'string',
        'detected_at' => 'datetime',
        'closed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function scopeOrganization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'scope_organization_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'owner_employee_id');
    }

    public function signals(): HasMany
    {
        return $this->hasMany(HrRiskSignal::class, 'risk_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(HrRiskAction::class, 'risk_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(HrRiskHistory::class, 'risk_id');
    }
}
