<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Work order pemeliharaan: diagnosis, rencana, biaya, dan penyelesaiannya.
 */
class MaintenanceWorkOrder extends Model
{
    use BelongsToSchool;

    protected $table = 'maintenance_work_orders';

    protected $fillable = [
        'work_order_number',
        'request_id',
        'asset_id',
        'facility_id',
        'type',
        'status',
        'priority',
        'title',
        'description',
        'diagnosis',
        'repair_plan',
        'assigned_to',
        'assigned_by',
        'assigned_at',
        'started_at',
        'due_at',
        'completed_at',
        'verified_by',
        'verified_at',
        'labor_cost',
        'parts_cost',
        'external_cost',
        'transport_cost',
        'other_cost',
        'total_cost',
        'vendor_id',
        'contract_id',
        'downtime_minutes',
        'finance_status',
        'finance_reference',
        'notes',
        'assigned_to',
    ];

    protected $casts = [
        'status' => \App\Modules\Asset\Domain\Enums\WorkOrderStatus::class,
        'type' => \App\Modules\Asset\Domain\Enums\WorkOrderType::class,
        'priority' => \App\Modules\Asset\Domain\Enums\MaintenancePriority::class,
        'assigned_at' => 'datetime',
        'started_at' => 'datetime',
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'verified_at' => 'datetime',
        'labor_cost' => 'decimal:2',
        'parts_cost' => 'decimal:2',
        'external_cost' => 'decimal:2',
        'transport_cost' => 'decimal:2',
        'other_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
    ];

    public function request(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'request_id');
    }

    public function asset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function facility(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function technician(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_to');
    }

    public function tasks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MaintenanceTask::class, 'work_order_id');
    }

    public function logs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MaintenanceLog::class, 'work_order_id');
    }

    public function contract(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MaintenanceContract::class, 'contract_id');
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && $this->completed_at === null
            && $this->due_at->isPast();
    }
}
