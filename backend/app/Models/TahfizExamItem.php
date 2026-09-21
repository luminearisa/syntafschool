<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu butir ujian.
 *
 * Untuk ujian sambung ayat / ayat acak: `ayah_start` adalah ayat yang
 * dibacakan penguji, `expected_ayah_end` kelanjutan yang diharapkan, dan
 * `actual_ayah_end` apa yang disebutkan peserta. Perbedaannya menjadi
 * kesalahan yang dicatat di `errors`.
 */
class TahfizExamItem extends BaseModel
{
    public const ITEM_SAMBUNG_AYAT = 'sambung_ayat';
    public const ITEM_ACAK_AYAT = 'acak_ayat';
    public const ITEM_HAFALAN = 'hafalan';
    public const ITEM_TAHSIN = 'tahsin';

    /** @var list<string> */
    public const ITEM_TYPES = [
        self::ITEM_SAMBUNG_AYAT, self::ITEM_ACAK_AYAT, self::ITEM_HAFALAN, self::ITEM_TAHSIN,
    ];

    protected $casts = [
        'score' => 'decimal:2',
        'errors' => 'array',
    ];

    public function examParticipant(): BelongsTo
    {
        return $this->belongsTo(TahfizExamParticipant::class, 'exam_participant_id');
    }

    public function surah(): BelongsTo
    {
        return $this->belongsTo(QuranSurah::class, 'surah_id');
    }

    /** True bila jawaban peserta tepat sama dengan kelanjutan yang diharapkan. */
    public function isCorrect(): bool
    {
        return $this->expected_ayah_end !== null
            && $this->actual_ayah_end === $this->expected_ayah_end;
    }
}
