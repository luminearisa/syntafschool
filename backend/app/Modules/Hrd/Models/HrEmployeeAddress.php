<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Alamat pegawai (§25). Mendukung banyak alamat (KTP, domisili, sementara) dengan
 * satu alamat utama — dibutuhkan laporan kependudukan dan penempatan unit.
 */
class HrEmployeeAddress extends HrdModel
{
    use SoftDeletes;

    protected $casts = [
        'is_primary' => 'boolean',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
