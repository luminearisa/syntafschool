<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sekuens penomoran dokumen HR (§14).
 *
 * Nomor diambil dengan `lockForUpdate` di dalam transaksi sehingga dua pegawai
 * yang dibuat bersamaan tidak mendapat nomor yang sama; `unique` pada kolom
 * nomor pegawai adalah jaring terakhirnya.
 */
class HrNumberSequence extends HrdModel
{
    protected $casts = [
        'year' => 'integer',
        'padding' => 'integer',
        'next_number' => 'integer',
        'last_issued_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }
}
