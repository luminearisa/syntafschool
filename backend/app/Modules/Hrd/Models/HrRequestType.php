<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jenis permintaan HR (§45).
 *
 * `editable_fields` adalah daftar putih kolom yang boleh diminta pegawai lewat
 * jenis permintaan ini. Inilah yang menutup celah paling umum pada self-service:
 * pegawai mengirim perubahan ke kolom sensitif hanya karena formulirnya
 * menerima apa saja.
 */
class HrRequestType extends HrdModel
{
    protected $casts = [
        'requires_approval' => 'boolean',
        'editable_fields' => 'array',
        'requires_attachment' => 'boolean',
        'auto_apply_on_approval' => 'boolean',
        'sla_hours' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function requests(): HasMany
    {
        return $this->hasMany(HrRequest::class, 'type_code', 'code');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Kolom yang boleh diminta; kosong berarti jenis ini bukan perubahan data. */
    public function allowsField(string $field): bool
    {
        $fields = $this->editable_fields;

        return is_array($fields) && in_array($field, $fields, true);
    }
}
