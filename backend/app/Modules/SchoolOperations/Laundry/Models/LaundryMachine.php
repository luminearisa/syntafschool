<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mesin cuci/pengering/setrika (§34); dapat ditautkan ke register Asset.
 */
class LaundryMachine extends Model
{
    protected $table = 'laundry_machines';

    protected $guarded = ['id'];

    protected $casts = [
        'capacity_kg' => 'decimal:2',
    ];

    public function batches(): HasMany
    {
        return $this->hasMany(LaundryBatch::class, 'laundry_machine_id');
    }
}
