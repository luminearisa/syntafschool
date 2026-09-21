<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kriteria rubrik sebuah penilaian.
 *
 * Baris ini mendefinisikan *apa yang dinilai* (kriteria + indikator + skor
 * maksimum). Skor per siswa per kriteria belum ditampung skema saat ini, jadi
 * penilaian rubrik dihitung manual oleh guru lalu dimasukkan sebagai satu skor
 * assessment biasa.
 */
class AssessmentRubric extends BaseModel
{
    protected $casts = [
        'max_score' => 'decimal:2',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }
}
