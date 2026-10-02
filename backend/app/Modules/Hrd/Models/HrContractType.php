<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jenis kontrak (§36). `is_fixed_term` menentukan kewajiban tanggal berakhir —
 * dan dengan begitu mengaktifkan pemeriksa "kontrak berjangka tanpa tanggal
 * berakhir", kesalahan administratif yang paling sering lolos.
 */
class HrContractType extends HrdModel
{
    protected $casts = [
        'is_fixed_term' => 'boolean',
        'default_duration_months' => 'integer',
        'reminder_days_before' => 'integer',
        'is_active' => 'boolean',
    ];

    public function contracts(): HasMany
    {
        return $this->hasMany(HrContract::class, 'contract_type_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
