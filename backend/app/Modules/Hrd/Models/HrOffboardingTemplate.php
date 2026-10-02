<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Template offboarding (§40). Dipilih dari worker type dan alasan keluar, sehingga
 * pegawai yang mengundurkan diri dan yang pensiun mendapat daftar penyelesaian
 * yang berbeda.
 */
class HrOffboardingTemplate extends HrdModel
{
    protected $casts = ['priority' => 'integer'];

    public function tasks(): HasMany
    {
        return $this->hasMany(HrOffboardingTemplateTask::class, 'template_id')->orderBy('sort_order');
    }

    public function processes(): HasMany
    {
        return $this->hasMany(HrOffboardingProcess::class, 'template_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
