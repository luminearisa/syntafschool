<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiRun;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrAiAuditLog extends Model
{
    protected $table = 'hr_ai_audit_logs';

    protected $guarded = [];

    protected $casts = [
        'run_id' => 'integer',
        'action' => 'string',
        'actor_type' => 'string',
        'actor_reference' => 'string',
        'subject_type' => 'string',
        'subject_id' => 'integer',
        'before_state' => 'array',
        'after_state' => 'array',
        'approval_required' => 'boolean',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'notes' => 'string',
        'occurred_at' => 'datetime',
        'meta' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(HrAiRun::class, 'run_id');
    }
}
