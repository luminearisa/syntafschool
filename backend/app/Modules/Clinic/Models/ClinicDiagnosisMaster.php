<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

/**
 * Master kode diagnosis; sistem kode dapat diganti lewat DiagnosisCodeProviderInterface.
 *
 * Tabel: `clinic_diagnoses_master`
 */
class ClinicDiagnosisMaster extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_diagnoses_master';

    protected $casts = [
        'is_infectious' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function diagnoses(): HasMany
    {
        return $this->hasMany(ClinicDiagnosis::class, 'master_id');
    }
    /** @param Builder<self> $query */
    public function scopeSearch(Builder $query, string $term): void
    {
        $query->where(fn (Builder $q) => $q->where('code', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%"));
    }
}
