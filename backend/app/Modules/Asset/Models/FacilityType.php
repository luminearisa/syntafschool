<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `facility_types` — modul Asset & Sarpras.
 */
class FacilityType extends Model
{
    use BelongsToSchool;

    protected $table = 'facility_types';

    protected $fillable = [
        'code',
        'name',
        'category',
        'default_capacity',
        'requires_approval',
        'is_bookable',
        'description',
    ];

    protected $casts = [
        'requires_approval' => 'boolean',
        'is_bookable' => 'boolean',
    ];

    public function facilities(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Facility::class);
    }

    public function attributes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FacilityAttribute::class);
    }
}
