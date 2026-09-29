<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\GradeSyncStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Riwayat percobaan sinkronisasi nilai (§6: Grade Sync History). */
class LmsGradeSyncAttempt extends BaseModel
{
    protected $table = 'lms_grade_sync_attempts';

    protected $casts = [
        'status' => GradeSyncStatus::class,
        'request' => 'array',
        'response' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'attempt_no' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function sync(): BelongsTo
    {
        return $this->belongsTo(LmsGradeSync::class, 'grade_sync_id');
    }
}
