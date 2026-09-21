<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kelompok mata pelajaran (Wajib, MIPA, IPS, Bahasa, Muatan Lokal, dst.).
 *
 * Dipakai sebagai label pengelompokan pada item struktur kurikulum.
 */
class SubjectGroup extends BaseModel
{
    use SoftDeletes;

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(CurriculumStructureItem::class);
    }
}
