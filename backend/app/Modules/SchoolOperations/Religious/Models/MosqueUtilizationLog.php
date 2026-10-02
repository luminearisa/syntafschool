<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `mosque_utilization_logs`.
 */
class MosqueUtilizationLog extends Model
{
    protected $table = 'mosque_utilization_logs';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'mosque_id' => 'integer',
        'area_id' => 'integer',
        'log_date' => 'date',
        'activity_id' => 'integer',
        'attendance_count' => 'integer',
        'capacity' => 'integer',
        'utilization_pct' => 'decimal:2',
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

    public function activity(): BelongsTo
    {
        return $this->belongsTo(ReligiousActivity::class, 'activity_id');
    }
}
