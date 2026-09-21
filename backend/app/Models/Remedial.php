<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan remedial satu siswa pada satu penilaian.
 *
 * `score_before` adalah nilai awal yang belum tuntas dan `score_after` hasil
 * remedial. Cara keduanya menjadi nilai akhir ditentukan `grading_formulas`
 * (`max`, `average`, atau `cap`) — lihat GradingService.
 *
 * Kolom ini unik per (assessment, student), jadi percobaan remedial berulang
 * ditulis menimpa baris yang sama; riwayat tiap percobaan ada di `notes` dan di
 * activity log.
 */
class Remedial extends BaseModel
{
    protected $casts = [
        'score_before' => 'decimal:2',
        'score_after' => 'decimal:2',
        'date' => 'date',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereNotNull('score_after');
    }

    /**
     * Kenaikan nilai yang dicapai; kosong bila remedial belum dinilai.
     */
    public function improvement(): ?float
    {
        if ($this->score_after === null || $this->score_before === null) {
            return null;
        }

        return round((float) $this->score_after - (float) $this->score_before, 2);
    }
}
