<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Target hafalan default program atau halaqah.
 *
 * Target individual ada di `TahfizIndividualTarget` — jangan memaksa seluruh
 * halaqah memiliki target yang sama.
 */
class TahfizTarget extends BaseModel
{
    public const SCOPE_PROGRAM = 'program';
    public const SCOPE_HALAQAH = 'halaqah';

    /** @var list<string> */
    public const SCOPES = [self::SCOPE_PROGRAM, self::SCOPE_HALAQAH];

    public const PERIOD_DAILY = 'daily';
    public const PERIOD_WEEKLY = 'weekly';
    public const PERIOD_MONTHLY = 'monthly';
    public const PERIOD_SEMESTER = 'semester';
    public const PERIOD_PROGRAM = 'program';

    /** @var list<string> */
    public const PERIODS = [
        self::PERIOD_DAILY, self::PERIOD_WEEKLY, self::PERIOD_MONTHLY,
        self::PERIOD_SEMESTER, self::PERIOD_PROGRAM,
    ];

    public const TARGET_JUZ = 'juz';
    public const TARGET_SURAH = 'surah';
    public const TARGET_AYAT = 'ayat';
    public const TARGET_PAGE = 'page';
    public const TARGET_LINE = 'line';

    /** @var list<string> */
    public const TARGET_TYPES = [
        self::TARGET_JUZ, self::TARGET_SURAH, self::TARGET_AYAT,
        self::TARGET_PAGE, self::TARGET_LINE,
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'is_active' => 'boolean',
        'effective_from' => 'date:Y-m-d',
        'effective_until' => 'date:Y-m-d',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(TahfizProgram::class, 'program_id');
    }

    public function halaqah(): BelongsTo
    {
        return $this->belongsTo(TahfizHalaqah::class, 'halaqah_id');
    }

    public function surah(): BelongsTo
    {
        return $this->belongsTo(QuranSurah::class, 'surah_id');
    }
}
