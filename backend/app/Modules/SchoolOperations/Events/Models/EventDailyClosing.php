<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EventDailyClosing — tabel `event_daily_closings`.
 *
 * Relasi hanya dibentuk untuk rujukan di dalam modul Event. Kolom yang
 * menunjuk modul lain diakses lewat kontrak/gateway, bukan relasi Eloquent.
 */
class EventDailyClosing extends Model
{
    use SoftDeletes;

    /** Tabel yang dipetakan (disebut eksplisit agar aman terhadap bentuk jamak tak beraturan). */
    protected $table = 'event_daily_closings';
    use SoftDeletes;
    /** Kolom yang boleh diisi massal. */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'schedule_id' => 'integer',
            'closing_date' => 'date',
            'day_number' => 'integer',
            'attendance_present' => 'integer',
            'attendance_absent' => 'integer',
            'attendance_no_show' => 'integer',
            'expense_amount' => 'decimal:2',
            'incident_count' => 'integer',
            'resource_issued' => 'integer',
            'resource_returned' => 'integer',
            'meals_served' => 'integer',
            'trip_completed' => 'integer',
            'participant_count' => 'integer',
            'closed_by' => 'integer',
            'closed_at' => 'datetime',
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
