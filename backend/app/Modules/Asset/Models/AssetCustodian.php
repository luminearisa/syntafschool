<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_custodians` — modul Asset & Sarpras.
 */
class AssetCustodian extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_custodians';

    protected $fillable = [
        'custodian_type',
        'name',
        'user_id',
        'unit_id',
        'location_id',
        'employee_id',
        'position',
        'phone',
        'is_active',
    ];

    protected $casts = [
        'custodian_type' => \App\Modules\Asset\Domain\Enums\CustodianType::class,
        'is_active' => 'boolean',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function unit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Unit::class, 'unit_id');
    }

    public function location(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function history(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetCustodianHistory::class, 'custodian_id');
    }
}
