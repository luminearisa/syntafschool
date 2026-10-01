<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `maintenance_requests` — modul Asset & Sarpras.
 */
class MaintenanceRequest extends Model
{
    use BelongsToSchool;

    protected $table = 'maintenance_requests';

    protected $fillable = [
        'request_number',
        'asset_id',
        'facility_id',
        'location_id',
        'requested_by',
        'source',
        'category',
        'priority',
        'status',
        'title',
        'description',
        'photo_path',
        'reported_at',
        'sla_due_at',
        'responded_at',
        'triaged_by',
        'triaged_at',
        'assigned_to',
        'work_order_id',
        'damage_report_id',
        'cost_estimate',
        'completed_at',
        'verified_by',
        'verified_at',
        'closed_at',
        'notes',
        'sla_due_at',
    ];

    protected $casts = [
        'status' => \App\Modules\Asset\Domain\Enums\MaintenanceRequestStatus::class,
        'priority' => \App\Modules\Asset\Domain\Enums\MaintenancePriority::class,
        'reported_at' => 'datetime',
        'sla_due_at' => 'datetime',
        'responded_at' => 'datetime',
        'triaged_at' => 'datetime',
        'cost_estimate' => 'decimal:2',
        'completed_at' => 'datetime',
        'verified_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function asset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function facility(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function requester(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'requested_by');
    }

    public function technician(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_to');
    }

    public function workOrders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MaintenanceWorkOrder::class, 'request_id');
    }

    public function isOverdue(): bool
    {
        return $this->sla_due_at !== null
            && $this->completed_at === null
            && $this->sla_due_at->isPast();
    }
}
