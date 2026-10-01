<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `maintenance_contracts` — modul Asset & Sarpras.
 */
class MaintenanceContract extends Model
{
    use BelongsToSchool;

    protected $table = 'maintenance_contracts';

    protected $fillable = [
        'vendor_id',
        'contract_number',
        'title',
        'start_date',
        'end_date',
        'sla',
        'service_frequency',
        'cost',
        'terms',
        'status',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'sla' => 'array',
        'cost' => 'decimal:2',
    ];

    public function assets(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'maintenance_contract_assets', 'contract_id', 'asset_id')
            ->withPivot(['covered_from', 'covered_until']);
    }

    public function workOrders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MaintenanceWorkOrder::class, 'contract_id');
    }
}
