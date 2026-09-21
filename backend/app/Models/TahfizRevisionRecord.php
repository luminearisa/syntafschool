<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catatan murojaah — aktivitas tersendiri dari setoran hafalan.
 *
 * Murojaah adalah pengulangan hafalan lama agar menjadi mutqin. Metodenya
 * bervariasi: individual, berpasangan, kelompok, tasmi', atau dibacakan kepada
 * guru. Peserta yang hafalan belum mutqin akan banyak muncul di sini.
 */
class TahfizRevisionRecord extends BaseModel
{
    use SoftDeletes;

    public const METHOD_INDIVIDUAL = 'individual';
    public const METHOD_PAIRED = 'paired';
    public const METHOD_GROUP = 'group';
    public const METHOD_TASMI = 'tasmi';
    public const METHOD_TEACHER_LISTENING = 'teacher_listening';

    /** @var list<string> */
    public const METHODS = [
        self::METHOD_INDIVIDUAL, self::METHOD_PAIRED, self::METHOD_GROUP,
        self::METHOD_TASMI, self::METHOD_TEACHER_LISTENING,
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'score' => 'decimal:2',
    ];

    public function scopeBetweenDates(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('date', [$from, $to]);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(TahfizEnrollment::class, 'enrollment_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(TahfizParticipant::class, 'participant_id');
    }

    public function halaqah(): BelongsTo
    {
        return $this->belongsTo(TahfizHalaqah::class, 'halaqah_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TahfizSession::class, 'session_id');
    }

    public function surah(): BelongsTo
    {
        return $this->belongsTo(QuranSurah::class, 'surah_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
