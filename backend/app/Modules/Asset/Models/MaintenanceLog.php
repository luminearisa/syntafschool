<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `maintenance_logs` — modul Asset & Sarpras.
 */
class MaintenanceLog extends Model
{
    protected $table = 'maintenance_logs';

    protected $fillable = [
        'work_order_id',
        'log_type',
        'message',
        'meta',
        'logged_by',
    ];

    protected $casts = [
        'meta' => 'array',
    ];
}
