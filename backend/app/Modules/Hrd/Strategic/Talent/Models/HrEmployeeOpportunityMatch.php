<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Talent\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrEmployeeOpportunityMatch extends Model
{
    protected $table = 'hr_employee_opportunity_matches';

    protected $guarded = [];

    protected $casts = [
        'opportunity_id' => 'integer',
        'employee_id' => 'integer',
        'match_score' => 'decimal:2',
        'skill_score' => 'decimal:2',
        'experience_score' => 'decimal:2',
        'interest_score' => 'decimal:2',
        'career_goal_score' => 'decimal:2',
        'location_score' => 'decimal:2',
        'availability_score' => 'decimal:2',
        'certification_score' => 'decimal:2',
        'performance_score' => 'decimal:2',
        'factors' => 'array',
        'recommendation' => 'string',
        'human_review_status' => 'string',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
        'notes' => 'string',
        'generated_at' => 'datetime',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
