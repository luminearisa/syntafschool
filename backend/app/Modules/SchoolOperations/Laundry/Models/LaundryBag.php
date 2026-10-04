<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Laundry bag (§16): identitas fisik tas (RFID/QR) yang mengikuti siklus order.
 */
class LaundryBag extends Model
{
    protected $table = 'laundry_bags';

    protected $guarded = ['id'];

    protected $casts = [
        'capacity_kg' => 'decimal:2',
        'tare_weight' => 'decimal:3',
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(LaundryBagAssignment::class);
    }
}
