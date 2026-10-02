<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParentTransportAuthorization extends Model
{

    protected $table = 'parent_transport_authorizations';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'student_id' => 'integer',
        'guardian_id' => 'integer',
        'pickup_stop_id' => 'integer',
        'dropoff_stop_id' => 'integer',
        'transportation_permission' => 'boolean',
        'valid_from' => 'date',
        'valid_until' => 'date',
    ];

    public function pickupStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'pickup_stop_id');
    }
    public function dropoffStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'dropoff_stop_id');
    }
}
