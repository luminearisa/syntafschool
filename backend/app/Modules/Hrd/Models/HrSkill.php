<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Keterampilan (§30). Master ini yang nantinya bertemu `hr_jobs.required_skills`
 * untuk menghasilkan analisis kesenjangan keterampilan pada fase berikutnya.
 */
class HrSkill extends HrdModel
{
    protected $casts = ['is_active' => 'boolean'];

    public function employeeSkills(): HasMany
    {
        return $this->hasMany(HrEmployeeSkill::class, 'skill_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
