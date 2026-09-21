<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catatan tahsin — penilaian perbaikan bacaan memakai rubrik.
 *
 * Tahsin bukan hafalan: penilaiannya per kriteria (makhraj, sifat huruf,
 * tajwid, mad, qalqalah, ghunnah, waqaf, ibtida, kelancaran, tartil) dengan
 * skor per kriteria di `TahfizTahsinScore`.
 */
class TahfizTahsinRecord extends BaseModel
{
    use SoftDeletes;

    protected $casts = [
        'date' => 'date:Y-m-d',
        'overall_score' => 'decimal:2',
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

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(TahfizRubric::class, 'rubric_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'instructor_id');
    }

    public function surah(): BelongsTo
    {
        return $this->belongsTo(QuranSurah::class, 'surah_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(TahfizTahsinScore::class, 'tahsin_record_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
