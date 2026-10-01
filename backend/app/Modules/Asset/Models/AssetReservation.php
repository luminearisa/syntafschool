<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_reservations` — modul Asset & Sarpras.
 */
class AssetReservation extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_reservations';

    protected $fillable = [
        'asset_id',
        'reserved_by',
        'purpose',
        'start_at',
        'end_at',
        'status',
        'expires_at',
        'loan_id',
        'notes',
    ];

    protected $casts = [
        'status' => \App\Modules\Asset\Domain\Enums\ReservationStatus::class,
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function asset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function reserver(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'reserved_by');
    }
}
