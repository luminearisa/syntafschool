<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Placement test calon peserta.
 *
 * Mengevaluasi tiga aspek — tilawah, hafalan, dan tahsin — melalui
 * `TahfizPlacementScore`. Hasilnya menentukan rekomendasi: level, juz awal,
 * dan target peserta.
 */
class TahfizPlacementTest extends BaseModel
{
    use SoftDeletes;

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_SCHEDULED, self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    public const ASPECT_TILAWAH = 'tilawah';
    public const ASPECT_HAFALAN = 'hafalan';
    public const ASPECT_TAHSIN = 'tahsin';

    /** @var list<string> */
    public const ASPECTS = [self::ASPECT_TILAWAH, self::ASPECT_HAFALAN, self::ASPECT_TAHSIN];

    protected $casts = [
        'test_date' => 'date:Y-m-d',
        'overall_score' => 'decimal:2',
        'recommended_juz_start' => 'integer',
    ];

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(TahfizParticipant::class, 'participant_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(TahfizProgram::class, 'program_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(TahfizEnrollment::class, 'enrollment_id');
    }

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'examiner_id');
    }

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(TahfizRubric::class, 'rubric_id');
    }

    public function recommendedLevel(): BelongsTo
    {
        return $this->belongsTo(TahfizLevel::class, 'recommended_level_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(TahfizPlacementScore::class, 'placement_test_id');
    }
}
