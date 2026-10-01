<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_identifiers` — modul Asset & Sarpras.
 */
class AssetIdentifier extends Model
{
    protected $table = 'asset_identifiers';

    protected $fillable = [
        'asset_id',
        'type',
        'value',
        'is_primary',
        'issued_at',
        'issued_by',
        'notes',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'issued_at' => 'datetime',
    ];
}
