<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiRun;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrAiUsageLog extends Model
{
    protected $table = 'hr_ai_usage_logs';

    protected $guarded = [];

    protected $casts = [
        'user_id' => 'integer',
        'feature' => 'string',
        'agent_code' => 'string',
        'run_id' => 'integer',
        'provider_code' => 'string',
        'model_code' => 'string',
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
        'latency_ms' => 'integer',
        'cost' => 'decimal:2',
        'currency' => 'string',
        'occurred_at' => 'datetime',
        'meta' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(HrAiRun::class, 'run_id');
    }
}
