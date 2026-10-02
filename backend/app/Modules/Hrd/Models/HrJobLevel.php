<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jenjang karier (§11): Junior → Middle → Senior → Supervisor → Manager →
 * Director → Executive. `rank` menentukan urutan; promosi fase berikutnya cukup
 * membandingkan rank, bukan menghafal daftar nama.
 */
class HrJobLevel extends HrdModel
{
    protected $casts = ['rank' => 'integer'];

    public function grades(): HasMany
    {
        return $this->hasMany(HrJobGrade::class, 'level_code', 'code');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(HrJob::class, 'job_level_code', 'code');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
