<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

/**
 * Master keluhan utama yang dapat dikonfigurasi.
 *
 * Tabel: `clinic_complaints`
 */
class ClinicComplaint extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_complaints';

    protected $casts = [
        'keywords' => 'array',
        'is_infectious' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function visits(): HasMany
    {
        return $this->hasMany(ClinicVisit::class, 'complaint_id');
    }
    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }
}
