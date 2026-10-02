<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\People\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrServiceInsight extends Model
{
    protected $table = 'hr_service_insights';

    protected $guarded = [];

    protected $casts = [
        'period' => 'string',
        'category' => 'string',
        'volume' => 'integer',
        'avg_resolution_hours' => 'decimal:2',
        'sla_met_rate' => 'decimal:2',
        'reopen_rate' => 'decimal:2',
        'satisfaction_avg' => 'decimal:2',
        'recurring_issue_signal' => 'string',
        'ai_detected_theme' => 'string',
        'evidence' => 'array',
        'generated_at' => 'datetime',
        'meta' => 'array',
    ];


}
