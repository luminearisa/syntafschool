<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `maintenance_tasks` — modul Asset & Sarpras.
 */
class MaintenanceTask extends Model
{
    protected $table = 'maintenance_tasks';

    protected $fillable = [
        'work_order_id',
        'sequence',
        'title',
        'description',
        'status',
        'completed_by',
        'completed_at',
        'notes',
    ];

    protected $casts = [
        'status' => \App\Modules\Asset\Domain\Enums\TaskStatus::class,
        'completed_at' => 'datetime',
    ];

    public function workOrder(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MaintenanceWorkOrder::class, 'work_order_id');
    }
}
