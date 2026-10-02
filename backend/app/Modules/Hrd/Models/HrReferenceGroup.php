<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kelompok referensi (organization_type, worker_type, employee_status, …).
 * `is_system` menandai kelompok yang dibaca mesin: labelnya boleh diubah,
 * barisnya tidak boleh dihapus (§18, §41).
 */
class HrReferenceGroup extends HrdModel
{
    protected $casts = ['is_system' => 'boolean'];

    public function items(): HasMany
    {
        return $this->hasMany(HrReferenceItem::class, 'group_code', 'code')->orderBy('sort_order');
    }

    public function activeItems(): HasMany
    {
        return $this->items()->where('is_active', true);
    }
}
