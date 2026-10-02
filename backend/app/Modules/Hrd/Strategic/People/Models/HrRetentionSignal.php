<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\People\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrRetentionSignal extends Model
{
    protected $table = 'hr_retention_signals';

    protected $guarded = [];

    protected $casts = [
        'employee_id' => 'integer',
        'signal_level' => 'string',
        'retention_score' => 'decimal:2',
        'factors' => 'array',
        'contributing_signals' => 'array',
        'evidence' => 'array',
        'period' => 'string',
        'tenure_months' => 'decimal:2',
        'manager_change_recent' => 'boolean',
        'promotion_gap_months' => 'integer',
        'compensation_percentile' => 'decimal:2',
        'engagement_score' => 'decimal:2',
        'attendance_irregularities' => 'integer',
        'learning_participation' => 'decimal:2',
        'career_progress_note' => 'string',
        'detected_at' => 'datetime',
        'status' => 'string',
        'reviewed_by' => 'integer',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
