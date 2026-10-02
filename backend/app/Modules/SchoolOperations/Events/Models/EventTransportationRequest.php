<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EventTransportationRequest — tabel `event_transportation_requests`.
 *
 * Relasi hanya dibentuk untuk rujukan di dalam modul Event. Kolom yang
 * menunjuk modul lain diakses lewat kontrak/gateway, bukan relasi Eloquent.
 */
class EventTransportationRequest extends Model
{
    use SoftDeletes;

    /** Tabel yang dipetakan (disebut eksplisit agar aman terhadap bentuk jamak tak beraturan). */
    protected $table = 'event_transportation_requests';
    use SoftDeletes;
    /** Kolom yang boleh diisi massal. */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'resource_request_id' => 'integer',
            'schedule_id' => 'integer',
            'trip_date' => 'date',
            'departure_at' => 'datetime',
            'return_at' => 'datetime',
            'vehicle_count' => 'integer',
            'passenger_count' => 'integer',
            'requires_driver' => 'boolean',
            'assisted_needed' => 'integer',
            'assigned_vehicles' => 'integer',
            'completed_trips' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'cost' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }
}
