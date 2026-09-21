<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ringkasan progress peserta per surah — dihitung ulang oleh progress engine
 * setiap kali setoran/murojaah diterima.
 *
 * Status membedakan empat tingkat penguasaan:
 * - belum        : belum pernah menyetor surah ini
 * - hafal        : sudah pernah menyetor
 * - lancar       : bisa membaca dengan sedikit kesalahan
 * - mutqin       : hafalan stabil, dapat dipanggil kembali dengan baik
 * - belum_mutqin : masih membutuhkan murojaah
 *
 * Penting: jumlah hafalan ≠ jumlah hafalan mutqin, sehingga keduanya dihitung
 * terpisah (`ayahs_memorized` vs `ayahs_mutqin`).
 */
class TahfizParticipantProgress extends BaseModel
{
    public const STATUS_BELUM = 'belum';
    public const STATUS_HAFAL = 'hafal';
    public const STATUS_LANCAR = 'lancar';
    public const STATUS_MUTQIN = 'mutqin';
    public const STATUS_BELUM_MUTQIN = 'belum_mutqin';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_BELUM, self::STATUS_HAFAL, self::STATUS_LANCAR,
        self::STATUS_MUTQIN, self::STATUS_BELUM_MUTQIN,
    ];

    /** Urutan tingkat penguasaan dari terendah. */
    public const STATUS_ORDER = [
        self::STATUS_BELUM => 0,
        self::STATUS_HAFAL => 1,
        self::STATUS_BELUM_MUTQIN => 2,
        self::STATUS_LANCAR => 3,
        self::STATUS_MUTQIN => 4,
    ];

    /** Label ramah-tampil untuk peta hafalan. */
    public const STATUS_LABELS = [
        self::STATUS_BELUM => 'Belum',
        self::STATUS_HAFAL => 'Hafal',
        self::STATUS_LANCAR => 'Lancar',
        self::STATUS_MUTQIN => 'Mutqin',
        self::STATUS_BELUM_MUTQIN => 'Perlu Murojaah',
    ];

    protected $casts = [
        'last_setor_date' => 'date:Y-m-d',
        'last_murojaah_date' => 'date:Y-m-d',
        'avg_score' => 'decimal:2',
    ];

    public function scopeMutqin(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_MUTQIN);
    }

    public function scopeNeedsMurojaah(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_BELUM_MUTQIN, self::STATUS_HAFAL]);
    }

    public function scopeInJuz(Builder $query, int $juz): Builder
    {
        return $query->where('juz', $juz);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(TahfizParticipant::class, 'participant_id');
    }

    public function surah(): BelongsTo
    {
        return $this->belongsTo(QuranSurah::class, 'surah_id');
    }

    /** Persentase surah yang sudah dihafal. */
    public function memorizedPercent(): float
    {
        $total = $this->surah?->ayah_count ?: 0;

        return $total > 0 ? round(($this->ayahs_memorized * 100) / $total, 1) : 0.0;
    }

    /** Persentase surah yang sudah mutqin. */
    public function mutqinPercent(): float
    {
        $total = $this->surah?->ayah_count ?: 0;

        return $total > 0 ? round(($this->ayahs_mutqin * 100) / $total, 1) : 0.0;
    }
}
