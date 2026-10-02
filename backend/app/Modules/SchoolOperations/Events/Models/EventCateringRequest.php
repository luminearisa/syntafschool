<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EventCateringRequest — tabel `event_catering_requests`.
 *
 * Relasi hanya dibentuk untuk rujukan di dalam modul Event. Kolom yang
 * menunjuk modul lain diakses lewat kontrak/gateway, bukan relasi Eloquent.
 */
class EventCateringRequest extends Model
{
    use SoftDeletes;

    /** Tabel yang dipetakan (disebut eksplisit agar aman terhadap bentuk jamak tak beraturan). */
    protected $table = 'event_catering_requests';
    use SoftDeletes;
    /** Kolom yang boleh diisi massal. */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'resource_request_id' => 'integer',
            'schedule_id' => 'integer',
            'request_date' => 'date',
            'serving_time' => 'datetime:H:i',
            'expected_participants' => 'integer',
            'confirmed_participants' => 'integer',
            'actual_participants' => 'integer',
            'dietary_summary' => 'array',
            'production_scheduled_at' => 'datetime',
            'distributed_at' => 'datetime',
            'portion_used' => 'integer',
            'cost_per_portion' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
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
