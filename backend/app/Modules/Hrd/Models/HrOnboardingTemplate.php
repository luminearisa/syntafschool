<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Template onboarding (§38).
 *
 * Pemilihan template dilakukan berurutan: worker type → posisi → departemen →
 * unit → lokasi, dengan `priority` sebagai penentu bila beberapa cocok. Dengan
 * begitu guru kontrak pesantren mendapat daftar tugas yang berbeda dari staf
 * kantor tanpa admin menyusun ulang setiap kali ada pegawai baru.
 */
class HrOnboardingTemplate extends HrdModel
{
    protected $casts = [
        'priority' => 'integer',
        'version' => 'integer',
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(HrOnboardingTemplateTask::class, 'template_id')->orderBy('sort_order');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(HrWorkLocation::class, 'location_id');
    }

    public function processes(): HasMany
    {
        return $this->hasMany(HrOnboardingProcess::class, 'template_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
