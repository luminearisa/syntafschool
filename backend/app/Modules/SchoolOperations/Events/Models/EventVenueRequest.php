<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EventVenueRequest — tabel `event_venue_requests`.
 *
 * Relasi hanya dibentuk untuk rujukan di dalam modul Event. Kolom yang
 * menunjuk modul lain diakses lewat kontrak/gateway, bukan relasi Eloquent.
 */
class EventVenueRequest extends Model
{
    use SoftDeletes;

    /** Tabel yang dipetakan (disebut eksplisit agar aman terhadap bentuk jamak tak beraturan). */
    protected $table = 'event_venue_requests';
    use SoftDeletes;
    /** Kolom yang boleh diisi massal. */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'venue_id' => 'integer',
            'facility_id' => 'integer',
            'requested_by' => 'integer',
            'required_capacity' => 'integer',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'requirement_detail' => 'array',
            'conflict_detail' => 'array',
            'conflict_count' => 'integer',
            'maintenance_flag' => 'boolean',
            'academic_conflict' => 'boolean',
            'religious_conflict' => 'boolean',
            'checked_at' => 'datetime',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'facility_booking_id' => 'integer',
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
