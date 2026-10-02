<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\People\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrOrganization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrExperienceSnapshot extends Model
{
    protected $table = 'hr_experience_snapshots';

    protected $guarded = [];

    protected $casts = [
        'snapshot_date' => 'date:Y-m-d',
        'granularity' => 'string',
        'scope' => 'string',
        'organization_id' => 'integer',
        'engagement_score' => 'decimal:2',
        'participation_rate' => 'decimal:2',
        'satisfaction_score' => 'decimal:2',
        'manager_effectiveness' => 'decimal:2',
        'career_confidence' => 'decimal:2',
        'learning_satisfaction' => 'decimal:2',
        'hr_service_satisfaction' => 'decimal:2',
        'service_volume' => 'integer',
        'avg_resolution_hours' => 'decimal:2',
        'reopen_rate' => 'decimal:2',
        'recognition_count' => 'integer',
        'respondents' => 'integer',
        'meets_privacy_threshold' => 'boolean',
        'breakdown' => 'array',
        'meta' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }
}
