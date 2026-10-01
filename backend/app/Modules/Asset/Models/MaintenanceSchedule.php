<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `maintenance_schedules` — modul Asset & Sarpras.
 */
class MaintenanceSchedule extends Model
{
    use BelongsToSchool;

    protected $table = 'maintenance_schedules';

    protected $fillable = [
        'asset_id',
        'facility_id',
        'category_id',
        'title',
        'frequency',
        'interval_days',
        'checklist',
        'next_due_at',
        'last_run_at',
        'assigned_role',
        'is_active',
        'last_work_order_id',
        'last_notified_at',
        'notes',
    ];

    protected $casts = [
        'frequency' => \App\Modules\Asset\Domain\Enums\MaintenanceFrequency::class,
        'checklist' => 'array',
        'next_due_at' => 'datetime',
        'last_run_at' => 'datetime',
        'is_active' => 'boolean',
        'last_notified_at' => 'datetime',
    ];

    public function asset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function facility(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }
}
