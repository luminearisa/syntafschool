<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;

/** Riwayat dokumen/laporan yang dihasilkan modul (program, hasil, evaluasi). */
class SupReport extends BaseModel
{
    protected $table = 'sup_reports';

    protected $casts = [
        'parameters' => 'array',
        'completed_at' => 'datetime',
    ];
}
