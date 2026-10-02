<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nilai referensi yang dapat diubah sekolah (§18, §41, §87).
 *
 * Kode disimpan — bukan label — pada baris transaksional, sehingga mengubah nama
 * tampilan tidak mengubah arti data lama.
 */
class HrReferenceItem extends HrdModel
{
    protected $casts = [
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'is_default' => 'boolean',
        'meta' => 'array',
        'sort_order' => 'integer',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(HrReferenceGroup::class, 'group_code', 'code');
    }

    public function scopeGroup(Builder $query, string $groupCode): Builder
    {
        return $query->where('group_code', $groupCode);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('label');
    }

    /** Atribut tambahan per jenis, mis. meta['requires_contract']. */
    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }
}
