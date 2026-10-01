<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Booking fasilitas dengan pemeriksaan benturan waktu.
 */
class FacilityBooking extends Model
{
    use BelongsToSchool;

    protected $table = 'facility_bookings';

    protected $fillable = [
        'booking_number',
        'facility_id',
        'location_id',
        'requested_by',
        'source',
        'status',
        'title',
        'purpose',
        'attendee_count',
        'start_at',
        'end_at',
        'requires_approval',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'checked_in_at',
        'completed_at',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'event_reference',
        'activity_reference',
        'academic_reference',
        'conflict_checked_at',
        'notes',
    ];

    protected $casts = [
        'status' => \App\Modules\Asset\Domain\Enums\BookingStatus::class,
        'source' => \App\Modules\Asset\Domain\Enums\BookingSource::class,
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'requires_approval' => 'boolean',
        'approved_at' => 'datetime',
        'checked_in_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'conflict_checked_at' => 'datetime',
    ];

    public function facility(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function location(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function requester(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'requested_by');
    }

    public function approver(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'approved_by');
    }
}
