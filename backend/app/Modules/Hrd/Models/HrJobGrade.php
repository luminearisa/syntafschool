<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Golongan jabatan (§11).
 *
 * `salary_band_reference` sengaja hanya berupa *rujukan* (mis. "G3-BAND"), bukan
 * nominal: Phase 1 tidak menyimpan angka gaji. Payroll/Compensation fase
 * berikutnya membaca rujukan ini dan menyimpan nominalnya di modulnya sendiri.
 */
class HrJobGrade extends HrdModel
{
    protected $casts = ['rank' => 'integer'];

    public function level(): BelongsTo
    {
        return $this->belongsTo(HrJobLevel::class, 'level_code', 'code');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(HrPosition::class, 'grade_code', 'code');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
