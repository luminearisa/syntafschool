<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_attribute_values` — modul Asset & Sarpras.
 */
class AssetAttributeValue extends Model
{
    protected $table = 'asset_attribute_values';

    protected $fillable = [
        'asset_id',
        'attribute_id',
        'value',
    ];
}
