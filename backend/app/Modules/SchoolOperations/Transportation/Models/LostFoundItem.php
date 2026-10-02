<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LostFoundItem extends Model
{

    protected $table = 'lost_found_items';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'trip_id' => 'integer',
        'vehicle_id' => 'integer',
        'student_id' => 'integer',
        'stop_id' => 'integer',
        'found_date' => 'date',
        'identified_by' => 'integer',
        'identified_at' => 'datetime',
        'owner_notified' => 'boolean',
        'returned_at' => 'datetime',
        'returned_to' => 'integer',
        'received_by' => 'integer',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(VehicleProfile::class, 'vehicle_id');
    }
}
