<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;

class TransportationEvent extends Model
{

    protected $table = 'transportation_events';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'aggregate_id' => 'integer',
        'payload' => 'array',
        'listener_count' => 'integer',
        'dispatch_result' => 'array',
        'actor_id' => 'integer',
        'occurred_at' => 'datetime',
    ];

}
