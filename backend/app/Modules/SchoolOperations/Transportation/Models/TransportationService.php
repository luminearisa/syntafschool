<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportationService extends Model
{

    protected $table = 'transportation_services';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'campus_id' => 'integer',
        'active' => 'boolean',
        'billable' => 'boolean',
        'fee_amount' => 'decimal:2',
        'max_passengers_per_route' => 'integer',
        'meta' => 'array',
    ];

    public function routes(): HasMany
    {
        return $this->hasMany(TransportationRoute::class, 'service_id');
    }
    public function assignments(): HasMany
    {
        return $this->hasMany(TransportationAssignment::class, 'service_id');
    }
    public function requests(): HasMany
    {
        return $this->hasMany(TransportationRequest::class, 'service_id');
    }
}
