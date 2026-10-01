<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_transfers` — modul Asset & Sarpras.
 */
class AssetTransfer extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_transfers';

    protected $fillable = [
        'transfer_number',
        'type',
        'status',
        'requested_by',
        'reason',
        'from_location_id',
        'to_location_id',
        'from_custodian_id',
        'to_custodian_id',
        'from_unit_id',
        'to_unit_id',
        'approved_by',
        'approved_at',
        'handed_over_at',
        'handover_by',
        'received_at',
        'received_by',
        'recipient_condition',
        'recipient_notes',
        'inspection_id',
        'document_reference',
    ];

    protected $casts = [
        'status' => \App\Modules\Asset\Domain\Enums\TransferStatus::class,
        'type' => \App\Modules\Asset\Domain\Enums\TransferType::class,
        'approved_at' => 'datetime',
        'handed_over_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetTransferItem::class, 'transfer_id');
    }

    public function fromLocation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function requester(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'requested_by');
    }

    public function receiver(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'received_by');
    }
}
