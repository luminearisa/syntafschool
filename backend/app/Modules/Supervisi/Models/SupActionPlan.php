<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use App\Modules\Supervisi\Domain\Enums\ActionPlanStatus;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Rencana Tindak Lanjut (RTL) setelah supervisi. */
class SupActionPlan extends BaseModel
{
    use SoftDeletes;

    protected $table = 'sup_action_plans';

    protected $casts = [
        'status' => ActionPlanStatus::class,
        'period_start' => 'date',
        'period_end' => 'date',
        'verified_at' => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(SupVisit::class, 'visit_id');
    }

    public function supervisee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisee_employee_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupActionItem::class, 'action_plan_id')->orderBy('sort_order');
    }
}
