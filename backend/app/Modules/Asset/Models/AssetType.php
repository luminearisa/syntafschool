<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_types` — modul Asset & Sarpras.
 */
class AssetType extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_types';

    protected $fillable = [
        'category_id',
        'code',
        'name',
        'specification_schema',
        'default_useful_life_months',
        'default_criticality',
        'default_depreciation_method',
        'is_active',
        'description',
    ];

    protected $casts = [
        'specification_schema' => 'array',
        'is_active' => 'boolean',
    ];

    public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function attributes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetAttribute::class, 'asset_type_id');
    }

    public function assets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Asset::class, 'asset_type_id');
    }
}
