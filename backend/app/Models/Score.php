<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Skor mentah satu siswa pada satu penilaian.
 *
 * Hanya menampung hasil mentah (`score` terhadap `assessments.max_score`).
 * Nilai akhir mata pelajaran tidak disimpan di sini — angka itu hasil
 * perhitungan bobot + remedial dan dihitung ulang lewat grading engine, supaya
 * perubahan bobot tidak membuat data tersimpan menjadi basi.
 *
 * Skor dihapus soft delete agar `score_revisions` (yang menempel lewat FK
 * cascade) tetap ada sebagai jejak audit.
 */
class Score extends BaseModel
{
    use SoftDeletes;

    protected $casts = [
        'score' => 'decimal:2',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ScoreRevision::class)->orderByDesc('id');
    }

    public function scopeGraded(Builder $query): Builder
    {
        return $query->whereNotNull('score');
    }

    /**
     * Nilai dalam persen (0–100) terhadap skor maksimum penilaiannya.
     */
    public function percentage(float $maxScore): ?float
    {
        if ($this->score === null || $maxScore <= 0) {
            return null;
        }

        // Dikalikan dulu baru dibagi: 23/40*100 menghasilkan 57.4999… di floating
        // point sehingga pembulatan nilai akhir melenceng satu angka.
        return round(((float) $this->score * 100) / $maxScore, 4);
    }
}
