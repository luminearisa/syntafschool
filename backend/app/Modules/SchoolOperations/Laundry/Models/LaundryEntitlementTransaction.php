<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ledger jatah (§13): ALLOCATE/CARRY_IN/USE/ADJUST/EXPIRE/REVERSE dengan rantai balance_after.
 */
class LaundryEntitlementTransaction extends Model
{
    protected $table = 'laundry_entitlement_transactions';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:3',
        'balance_after' => 'decimal:3',
        'occurred_at' => 'datetime',
    ];

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(LaundryEntitlement::class, 'laundry_entitlement_id');
    }
}
