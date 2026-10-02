<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Strategic\Governance\Models\HrRisk;
use App\Modules\Hrd\Strategic\Governance\Models\HrStrategicHrAction;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrRiskAction extends Model
{
    protected $table = 'hr_risk_actions';

    protected $guarded = [];

    protected $casts = [
        'risk_id' => 'integer',
        'action_type' => 'string',
        'title' => 'string',
        'description' => 'string',
        'owner_employee_id' => 'integer',
        'due_date' => 'date:Y-m-d',
        'status' => 'string',
        'outcome' => 'string',
        'strategic_action_id' => 'integer',
        'meta' => 'array',
    ];

    public function risk(): BelongsTo
    {
        return $this->belongsTo(HrRisk::class, 'risk_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'owner_employee_id');
    }

    public function strategicAction(): BelongsTo
    {
        return $this->belongsTo(HrStrategicHrAction::class, 'strategic_action_id');
    }
}
