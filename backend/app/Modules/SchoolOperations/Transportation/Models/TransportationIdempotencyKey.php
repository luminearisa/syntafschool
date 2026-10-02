<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;

class TransportationIdempotencyKey extends Model
{

    protected $table = 'transportation_idempotency_keys';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'result_id' => 'integer',
        'response' => 'array',
        'processed_at' => 'datetime',
    ];

}
