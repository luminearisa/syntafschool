<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_categories` — modul Asset & Sarpras.
 */
class AssetCategory extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_categories';

    protected $fillable = [
        'parent_id',
        'code',
        'name',
        'kind',
        'depreciation_method',
        'useful_life_months',
        'capitalization_threshold',
        'maintenance_policy',
        'inspection_frequency_days',
        'default_criticality',
        'is_consumable',
        'is_active',
        'description',
    ];

    protected $casts = [
        'maintenance_policy' => 'array',
        'is_consumable' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function types(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetType::class, 'category_id');
    }

    public function assets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Asset::class, 'category_id');
    }
}
