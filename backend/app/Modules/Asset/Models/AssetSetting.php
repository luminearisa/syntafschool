<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_settings` — modul Asset & Sarpras.
 */
class AssetSetting extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_settings';

    protected $fillable = [
        'key',
        'value',
        'description',
        'updated_by',
    ];

    protected $casts = [
        'value' => 'array',
    ];
}
