<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_custodian_history` — modul Asset & Sarpras.
 */
class AssetCustodianHistory extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_custodian_history';

    protected $fillable = [
        'asset_id',
        'custodian_id',
        'custodian_type',
        'custodian_label',
        'location_id',
        'assigned_at',
        'released_at',
        'assigned_by',
        'released_by',
        'reason',
        'transfer_id',
        'handover_document',
        'acknowledgement',
    ];

    protected $casts = [
        'custodian_type' => \App\Modules\Asset\Domain\Enums\CustodianType::class,
        'assigned_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function asset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function custodian(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AssetCustodian::class, 'custodian_id');
    }
}
