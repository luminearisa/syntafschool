<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jenis sertifikasi (§29). Pemisahan jenis vs milik-pegawai membuat pertanyaan
 * "siapa yang sertifikatnya berakhir bulan ini?" terjawab lewat indeks tanggal,
 * bukan pemindaian teks.
 */
class HrCertification extends HrdModel
{
    protected $casts = [
        'requires_expiry' => 'boolean',
        'is_mandatory_for_teachers' => 'boolean',
        'applies_to' => 'array',
        'validity_months' => 'integer',
        'is_active' => 'boolean',
    ];

    public function employeeCertifications(): HasMany
    {
        return $this->hasMany(HrEmployeeCertification::class, 'certification_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
