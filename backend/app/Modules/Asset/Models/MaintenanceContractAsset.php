<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `maintenance_contract_assets` — modul Asset & Sarpras.
 */
class MaintenanceContractAsset extends Model
{
    protected $table = 'maintenance_contract_assets';

    protected $fillable = [
        'contract_id',
        'asset_id',
        'covered_from',
        'covered_until',
    ];

    protected $casts = [
        'covered_from' => 'date',
        'covered_until' => 'date',
    ];
}
