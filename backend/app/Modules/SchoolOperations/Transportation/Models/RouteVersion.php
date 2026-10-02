<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RouteVersion extends Model
{

    protected $table = 'route_versions';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'route_id' => 'integer',
        'version_number' => 'integer',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'distance_km' => 'decimal:2',
        'estimated_duration_minutes' => 'integer',
        'snapshot' => 'array',
        'created_by' => 'integer',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportationRoute::class, 'route_id');
    }
    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class, 'route_version_id');
    }
    public function stopVersions(): HasMany
    {
        return $this->hasMany(RouteStopVersion::class, 'route_version_id');
    }
}
