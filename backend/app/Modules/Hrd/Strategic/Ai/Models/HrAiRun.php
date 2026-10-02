<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiRecommendation;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiToolCall;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrAiRun extends Model
{
    protected $table = 'hr_ai_runs';

    protected $guarded = [];

    protected $casts = [
        'conversation_id' => 'integer',
        'code' => 'string',
        'agent_code' => 'string',
        'user_id' => 'integer',
        'employee_id' => 'integer',
        'intent' => 'string',
        'intent_confidence' => 'decimal:2',
        'prompt' => 'string',
        'prompt_redacted' => 'array',
        'model_code' => 'string',
        'provider_code' => 'string',
        'status' => 'string',
        'response' => 'string',
        'response_payload' => 'array',
        'tools_used' => 'array',
        'data_sources' => 'array',
        'calculation' => 'array',
        'assumptions' => 'array',
        'confidence' => 'decimal:2',
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
        'latency_ms' => 'integer',
        'cost' => 'decimal:2',
        'requires_approval' => 'boolean',
        'approval_status' => 'string',
        'error' => 'string',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'idempotency_key' => 'string',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(HrAiToolCall::class, 'run_id');
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(HrAiRecommendation::class, 'run_id');
    }
}
