<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TripManifest extends Model
{

    protected $table = 'trip_manifests';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'trip_id' => 'integer',
        'route_version_id' => 'integer',
        'expected_passengers' => 'integer',
        'boarded_passengers' => 'integer',
        'dropped_off_passengers' => 'integer',
        'absent_passengers' => 'integer',
        'no_show_passengers' => 'integer',
        'capacity' => 'integer',
        'occupancy_percent' => 'decimal:2',
        'generated_by' => 'integer',
        'generated_at' => 'datetime',
        'finalized_at' => 'datetime',
        'meta' => 'array',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }
    public function passengers(): HasMany
    {
        return $this->hasMany(TripPassenger::class, 'manifest_id');
    }
}
