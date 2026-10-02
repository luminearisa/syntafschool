<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EventChangeRequest — tabel `event_change_requests`.
 *
 * Relasi hanya dibentuk untuk rujukan di dalam modul Event. Kolom yang
 * menunjuk modul lain diakses lewat kontrak/gateway, bukan relasi Eloquent.
 */
class EventChangeRequest extends Model
{
    use SoftDeletes;

    /** Tabel yang dipetakan (disebut eksplisit agar aman terhadap bentuk jamak tak beraturan). */
    protected $table = 'event_change_requests';
    use SoftDeletes;
    /** Kolom yang boleh diisi massal. */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'impact_analysis' => 'array',
            'affected_sessions' => 'integer',
            'affected_participants' => 'integer',
            'affected_catering' => 'integer',
            'affected_transportation' => 'integer',
            'affected_facility' => 'integer',
            'affected_notification' => 'integer',
            'cost_impact' => 'decimal:2',
            'schedule_impact_days' => 'integer',
            'requested_by' => 'integer',
            'requested_at' => 'datetime',
            'old_value' => 'array',
            'new_value' => 'array',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'applied_at' => 'datetime',
            'resulting_version' => 'integer',
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
