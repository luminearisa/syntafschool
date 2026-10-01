<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\FacilityBookingStatus;

/**
 * Peminjaman ruang/lapangan lewat FacilityProviderInterface — bukan pemesanan ruang versi modul ini.
 *
 * Tabel: `activity_facility_bookings`
 */
class ActivityFacilityBooking extends ActivityModel
{
    protected $table = 'activity_facility_bookings';

    protected $casts = [
        'status' => FacilityBookingStatus::class,
        'date' => 'date',
        'requested_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ActivitySchedule::class, 'schedule_id');
    }
    public function session(): BelongsTo
    {
        return $this->belongsTo(ActivitySession::class, 'session_id');
    }
    public function room(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Room::class, 'room_id');
    }
    public function requester(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'requested_by');
    }
}
