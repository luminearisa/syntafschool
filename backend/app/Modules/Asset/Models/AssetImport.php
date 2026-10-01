<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_imports` — modul Asset & Sarpras.
 */
class AssetImport extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_imports';

    protected $fillable = [
        'type',
        'file_path',
        'original_name',
        'status',
        'summary',
        'preview',
        'error_path',
        'created_by',
        'confirmed_at',
    ];

    protected $casts = [
        'summary' => 'array',
        'preview' => 'array',
        'confirmed_at' => 'datetime',
    ];
}
