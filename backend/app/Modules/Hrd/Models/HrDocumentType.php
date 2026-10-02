<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jenis dokumen (§31) — konfigurasi, bukan enum. `applies_to` menentukan jenis
 * dokumen mana yang wajib bagi worker type/posisi tertentu, sehingga pemeriksa
 * kualitas data dapat mengeluh dengan tepat sasaran.
 */
class HrDocumentType extends HrdModel
{
    protected $casts = [
        'requires_number' => 'boolean',
        'requires_expiry' => 'boolean',
        'is_mandatory' => 'boolean',
        'applies_to' => 'array',
        'retention_months' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(HrEmployeeDocument::class, 'document_type_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
