<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Succession\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Strategic\Succession\Models\HrSuccessionPlan;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrSuccessionReadiness extends Model
{
    protected $table = 'hr_succession_readiness';

    protected $guarded = [];

    protected $casts = [
        'candidate_id' => 'integer',
        'plan_id' => 'integer',
        'employee_id' => 'integer',
        'skills_score' => 'decimal:2',
        'performance_score' => 'decimal:2',
        'competency_score' => 'decimal:2',
        'experience_score' => 'decimal:2',
        'aspiration_score' => 'decimal:2',
        'development_score' => 'decimal:2',
        'certification_score' => 'decimal:2',
        'leadership_score' => 'decimal:2',
        'readiness_score' => 'decimal:2',
        'readiness_level' => 'string',
        'evidence' => 'array',
        'methodology' => 'string',
        'calculated_at' => 'datetime',
        'meta' => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(HrSuccessionPlan::class, 'plan_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
