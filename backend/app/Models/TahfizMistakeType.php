<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Taksonomi kesalahan hafalan/bacaan.
 *
 * Dipakai `tahfiz_memorization_mistakes` agar kesalahan tidak hanya tersimpan
 * sebagai skor, melainkan terkategori: lupa ayat, salah sambung, salah lafadz,
 * tajwid, makhraj, harakat, waqaf, mad, ghunnah, dan seterusnya. Inilah
 * fondasi analitik kesalahan peserta.
 */
class TahfizMistakeType extends BaseModel
{
    public const CATEGORY_MEMORIZATION = 'memorization';
    public const CATEGORY_PRONUNCIATION = 'pronunciation';
    public const CATEGORY_TAJWID = 'tajwid';
    public const CATEGORY_READING = 'reading';
    public const CATEGORY_OTHER = 'other';

    /** @var list<string> */
    public const CATEGORIES = [
        self::CATEGORY_MEMORIZATION, self::CATEGORY_PRONUNCIATION,
        self::CATEGORY_TAJWID, self::CATEGORY_READING, self::CATEGORY_OTHER,
    ];

    protected $casts = [
        'severity_weight' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
