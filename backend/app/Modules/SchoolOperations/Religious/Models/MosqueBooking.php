<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `mosque_bookings`.
 */
class MosqueBooking extends Model
{
    protected $table = 'mosque_bookings';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'mosque_id' => 'integer',
        'area_id' => 'integer',
        'requested_by' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'expected_attendees' => 'integer',
        'workflow_instance_id' => 'integer',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'meta' => 'array',
    ];

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(MosqueArea::class, 'area_id');
    }
}
