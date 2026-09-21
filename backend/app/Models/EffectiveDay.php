<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hari efektif — satu baris per tanggal per (semester, jenjang).
 *
 * `is_school_day = true` berarti tanggal itu hari sekolah; `false` berarti
 * bukan (Minggu atau hari libur). Rekap per bulan adalah agregasi turunan.
 */
class EffectiveDay extends BaseModel
{
    protected $casts = [
        'date' => 'date',
        'is_school_day' => 'boolean',
    ];

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class);
    }
}
