<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteStopVersion extends Model
{

    protected $table = 'route_stop_versions';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'route_version_id' => 'integer',
        'route_stop_id' => 'integer',
        'sequence' => 'integer',
        'attributes' => 'array',
    ];

    public function routeVersion(): BelongsTo
    {
        return $this->belongsTo(RouteVersion::class, 'route_version_id');
    }
    public function routeStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'route_stop_id');
    }
}
