<?php

namespace App\Modules\Hrd\Models;

/**
 * Jenis cuti (§Fondasi Leave). Phase 1 hanya menyiapkan katalognya supaya mesin
 * transaksi dapat menandai pegawai berstatus cuti dengan alasan yang sah —
 * saldo dan persetujuan cuti milik fase berikutnya.
 */
class HrLeaveType extends HrdModel
{
    protected $casts = [
        'is_paid' => 'boolean',
        'requires_approval' => 'boolean',
        'max_days_per_year' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
