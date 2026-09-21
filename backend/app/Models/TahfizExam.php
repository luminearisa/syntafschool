<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ujian Tahfiz: hafalan, sambung ayat, ayat acak, tasmi', tahsin, atau
 * kenaikan level.
 *
 * Untuk ujian sambung/ayat acak, `TahfizExamService` memilih ayat acak dari
 * juz/surah yang ditentukan — sistem yang mencatat ayat awal, kelanjutan yang
 * diharapkan, jawaban peserta, dan kesalahannya.
 */
class TahfizExam extends BaseModel
{
    use SoftDeletes;

    public const TYPE_UJIAN_HAFALAN = 'ujian_hafalan';
    public const TYPE_SAMBUNG_AYAT = 'sambung_ayat';
    public const TYPE_ACAK_AYAT = 'acak_ayat';
    public const TYPE_TASMI = 'tasmi';
    public const TYPE_UJIAN_TAHSIN = 'ujian_tahsin';
    public const TYPE_KENAIKAN_LEVEL = 'kenaikan_level';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_UJIAN_HAFALAN, self::TYPE_SAMBUNG_AYAT, self::TYPE_ACAK_AYAT,
        self::TYPE_TASMI, self::TYPE_UJIAN_TAHSIN, self::TYPE_KENAIKAN_LEVEL,
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ONGOING = 'ongoing';
    public const STATUS_DONE = 'done';
    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_ONGOING,
        self::STATUS_DONE, self::STATUS_CANCELLED,
    ];

    protected $casts = [
        'exam_date' => 'date:Y-m-d',
        'start_time' => 'datetime:H:i',
        'max_score' => 'decimal:2',
        'passing_score' => 'decimal:2',
    ];

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('exam_date', '>=', now()->toDateString())
            ->whereIn('status', [self::STATUS_SCHEDULED, self::STATUS_ONGOING])
            ->orderBy('exam_date');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(TahfizProgram::class, 'program_id');
    }

    public function halaqah(): BelongsTo
    {
        return $this->belongsTo(TahfizHalaqah::class, 'halaqah_id');
    }

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(TahfizRubric::class, 'rubric_id');
    }

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'examiner_id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(TahfizLevel::class, 'level_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(TahfizExamParticipant::class, 'exam_id');
    }
}
