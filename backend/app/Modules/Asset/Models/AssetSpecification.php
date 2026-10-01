<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_specifications` — modul Asset & Sarpras.
 */
class AssetSpecification extends Model
{
    protected $table = 'asset_specifications';

    protected $fillable = [
        'asset_id',
        'key',
        'value',
        'unit',
        'source',
    ];
}
