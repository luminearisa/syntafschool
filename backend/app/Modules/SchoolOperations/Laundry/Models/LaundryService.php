<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master layanan laundry (§3): Regular, Express, Bedding, dst.
 */
class LaundryService extends Model
{
    protected $table = 'laundry_services';

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'processing_hours' => 'integer',
        'sort_order' => 'integer',
    ];

    public function pricingRules(): HasMany
    {
        return $this->hasMany(LaundryPricingRule::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(LaundryOrder::class);
    }
}
