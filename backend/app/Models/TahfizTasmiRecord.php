<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tasmi' — setoran hafalan dalam jumlah besar (1/5/10/30 juz) di hadapan
 * penguji. Berbeda dari setoran harian: hasilnya lulus / lulus dengan catatan /
 * ulang, dan dapat memicu penerbitan sertifikat.
 */
class TahfizTasmiRecord extends BaseModel
{
    use SoftDeletes;

    public const RESULT_LULUS = 'lulus';
    public const RESULT_LULUS_DENGAN_CATATAN = 'lulus_dengan_catatan';
    public const RESULT_ULANG = 'ulang';

    /** @var list<string> */
    public const RESULTS = [
        self::RESULT_LULUS, self::RESULT_LULUS_DENGAN_CATATAN, self::RESULT_ULANG,
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'score' => 'decimal:2',
    ];

    public function scopePassed(Builder $query): Builder
    {
        return $query->whereIn('result', [self::RESULT_LULUS, self::RESULT_LULUS_DENGAN_CATATAN]);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(TahfizEnrollment::class, 'enrollment_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(TahfizParticipant::class, 'participant_id');
    }

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'examiner_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Jumlah juz yang disetorkan. */
    public function juzCount(): int
    {
        return max(0, (int) $this->juz_to - (int) $this->juz_from + 1);
    }
}
