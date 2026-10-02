<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiRun;
use App\Modules\Hrd\Strategic\Governance\Models\HrStrategicHrAction;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrAiRecommendation extends Model
{
    protected $table = 'hr_ai_recommendations';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'run_id' => 'integer',
        'recommendation_type' => 'string',
        'title' => 'string',
        'problem' => 'string',
        'evidence' => 'array',
        'options' => 'array',
        'expected_impact' => 'string',
        'estimated_cost' => 'decimal:2',
        'risks' => 'array',
        'dependencies' => 'array',
        'recommended_next_step' => 'string',
        'confidence' => 'decimal:2',
        'owner_role' => 'string',
        'status' => 'string',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
        'human_decision' => 'string',
        'strategic_action_id' => 'integer',
        'meta' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(HrAiRun::class, 'run_id');
    }

    public function strategicAction(): BelongsTo
    {
        return $this->belongsTo(HrStrategicHrAction::class, 'strategic_action_id');
    }
}
