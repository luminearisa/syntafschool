<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportationWaitlist extends Model
{

    protected $table = 'transportation_waitlists';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'request_id' => 'integer',
        'student_id' => 'integer',
        'route_id' => 'integer',
        'service_id' => 'integer',
        'priority_score' => 'integer',
        'special_need' => 'boolean',
        'boarding_status' => 'boolean',
        'manual_priority' => 'boolean',
        'requested_at' => 'datetime',
        'seat_offer_assignment_id' => 'integer',
        'offer_expires_at' => 'datetime',
        'promoted_at' => 'datetime',
        'promoted_by' => 'integer',
        'history' => 'array',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TransportationRequest::class, 'request_id');
    }
    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportationRoute::class, 'route_id');
    }
    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentTransportProfile::class, 'student_id');
    }
}
