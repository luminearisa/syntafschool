<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aturan harga efektif-berjangka (§4). Rule historis tidak dihapus; order menyimpan snapshot.
 */
class LaundryPricingRule extends Model
{
    protected $table = 'laundry_pricing_rules';

    protected $guarded = ['id'];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'minimum_quantity' => 'decimal:3',
        'minimum_charge' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'fee_percent' => 'decimal:2',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(LaundryService::class, 'laundry_service_id');
    }
}
