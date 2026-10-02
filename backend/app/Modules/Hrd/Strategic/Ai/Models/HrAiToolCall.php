<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiRun;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrAiToolCall extends Model
{
    protected $table = 'hr_ai_tool_calls';

    protected $guarded = [];

    protected $casts = [
        'run_id' => 'integer',
        'tool_code' => 'string',
        'arguments' => 'array',
        'redacted_arguments' => 'array',
        'result' => 'array',
        'status' => 'string',
        'denial_reason' => 'string',
        'permission_checked' => 'string',
        'latency_ms' => 'integer',
        'called_at' => 'datetime',
        'meta' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(HrAiRun::class, 'run_id');
    }
}
