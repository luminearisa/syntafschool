<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Referensi 114 surah Al-Quran berdasarkan mushaf Utsmani (604 halaman).
 *
 * Ini adalah *single source of truth* struktur mushaf: progress engine
 * menghitung halaman/ayat dari sini, dan ujian acak ayat mengambil ayat dari
 * sini. Tabel ini diisi seeder dan tidak boleh diubah lewat aplikasi.
 */
class QuranSurah extends BaseModel
{
    protected $casts = [
        'number' => 'integer',
        'ayah_count' => 'integer',
        'page_start' => 'integer',
        'page_end' => 'integer',
        'juz_start' => 'integer',
        'juz_end' => 'integer',
    ];

    /** Jumlah halaman yang dilalui surah ini di mushaf. */
    public function pageCount(): int
    {
        return max(0, $this->page_end - $this->page_start + 1);
    }

    public function scopeMakkah(Builder $query): Builder
    {
        return $query->where('revelation_place', 'makkah');
    }

    public function scopeMadinah(Builder $query): Builder
    {
        return $query->where('revelation_place', 'madinah');
    }

    public function scopeInJuz(Builder $query, int $juz): Builder
    {
        return $query->where('juz_start', '<=', $juz)->where('juz_end', '>=', $juz);
    }

    public function memorizationRecords(): HasMany
    {
        return $this->hasMany(TahfizMemorizationRecord::class, 'surah_id');
    }
}
