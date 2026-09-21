<?php

namespace App\Models;

use App\Models\Concerns\LogsPpdbActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tahap seleksi (administratif, tes akademik, wawancara, tahfidz, psikotes,
 * kesehatan).
 */
class PpdbSelectionStage extends BaseModel
{
    use LogsPpdbActivity, SoftDeletes;

    public const TYPE_ADMINISTRATIVE = 'administrative';
    public const TYPE_ACADEMIC_TEST = 'academic_test';
    public const TYPE_INTERVIEW = 'interview';
    public const TYPE_TAHFIDZ = 'tahfidz';
    public const TYPE_PSYCHOLOGICAL = 'psychological_test';
    public const TYPE_HEALTH = 'health_check';
    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_ADMINISTRATIVE, self::TYPE_ACADEMIC_TEST, self::TYPE_INTERVIEW,
        self::TYPE_TAHFIDZ, self::TYPE_PSYCHOLOGICAL, self::TYPE_HEALTH, self::TYPE_OTHER,
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ONGOING = 'ongoing';
    public const STATUS_DONE = 'done';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_ONGOING, self::STATUS_DONE, self::STATUS_CANCELLED,
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'passing_score' => 'decimal:2',
        'weight' => 'decimal:2',
        'quota' => 'integer',
        'order' => 'integer',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PpdbPeriod::class, 'period_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(PpdbProgram::class, 'program_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(PpdbSelectionParticipant::class, 'stage_id');
    }

    public function activityEventPrefix(): string
    {
        return 'PPDB_SELECTION_STAGE';
    }
}