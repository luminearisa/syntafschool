<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_attributes` — modul Asset & Sarpras.
 */
class AssetAttribute extends Model
{
    protected $table = 'asset_attributes';

    protected $fillable = [
        'asset_type_id',
        'code',
        'label',
        'data_type',
        'unit',
        'options',
        'is_required',
        'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
    ];
}
