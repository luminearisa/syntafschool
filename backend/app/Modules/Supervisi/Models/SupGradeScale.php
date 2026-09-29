<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;

/**
 * Skala nilai / ambang predikat (mis. A/B/C/K 90/80/70 atau sebutan PKG).
 *
 * `bands` disimpan sebagai daftar rentang sehingga sekolah dapat mengubah
 * ambang tanpa mengubah kode.
 */
class SupGradeScale extends BaseModel
{
    protected $table = 'sup_grade_scales';

    protected $casts = [
        'bands' => 'array',
        'is_default' => 'boolean',
        'is_system' => 'boolean',
    ];

    /** Label band untuk skor tertentu (dipakai mesin skor). */
    public function bandFor(?float $score, string $type = 'PERCENT'): ?array
    {
        if ($score === null) {
            return null;
        }

        foreach ((array) $this->bands as $band) {
            $min = $band['min'] ?? null;
            $max = $band['max'] ?? null;

            $aboveMin = $min === null || $score > (float) $min || (($band['min_inclusive'] ?? false) && $score >= (float) $min);
            $belowMax = $max === null || $score <= (float) $max;

            if ($aboveMin && $belowMax) {
                return $band;
            }
        }

        return null;
    }
}
