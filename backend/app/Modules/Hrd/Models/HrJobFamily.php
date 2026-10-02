<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bidang pekerjaan (§10). Dipakai lintas fase: rekrutmen, kinerja, kompensasi,
 * pembelajaran, dan perencanaan tenaga kerja semuanya menyaring per job family.
 */
class HrJobFamily extends HrdModel
{
    protected $casts = ['sort_order' => 'integer'];

    public function jobs(): HasMany
    {
        return $this->hasMany(HrJob::class, 'job_family_code', 'code');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
