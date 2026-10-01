<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_inspection_templates` — modul Asset & Sarpras.
 */
class AssetInspectionTemplate extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_inspection_templates';

    protected $fillable = [
        'category_id',
        'code',
        'name',
        'inspection_type',
        'checklist',
        'is_active',
    ];

    protected $casts = [
        'inspection_type' => \App\Modules\Asset\Domain\Enums\InspectionType::class,
        'checklist' => 'array',
        'is_active' => 'boolean',
    ];
}
