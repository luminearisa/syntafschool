<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\GradeReleaseStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot rilis nilai akhir (§16–§18).
 *
 * Menyimpan rincian perhitungan per komponen sehingga bila konfigurasi
 * gradebook berubah kemudian, nilai yang sudah dirilis tetap dapat diaudit.
 */
class LmsGradeRelease extends BaseModel
{
    protected $table = 'lms_grade_releases';

    protected $casts = [
        'status' => GradeReleaseStatus::class,
        'final_score' => 'decimal:2',
        'normalized_score' => 'decimal:2',
        'passed' => 'boolean',
        'component_snapshot' => 'array',
        'calculation_snapshot' => 'array',
        'released_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function gradebook(): BelongsTo
    {
        return $this->belongsTo(LmsGradebook::class, 'gradebook_id');
    }

    public function isVisible(): bool
    {
        return $this->status->isVisible();
    }

    /**
     * Rincian kontribusi tiap komponen (§15) — contoh keluaran:
     * Assignments weight 30, score 80, contribution 24.
     *
     * @return list<array<string, mixed>>
     */
    public function breakdown(): array
    {
        return $this->component_snapshot ?? [];
    }
}
