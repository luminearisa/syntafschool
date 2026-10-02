<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `religious_daily_closings`.
 */
class ReligiousDailyClosing extends Model
{
    protected $table = 'religious_daily_closings';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'closing_date' => 'date',
        'campus_id' => 'integer',
        'mosque_id' => 'integer',
        'scope_id' => 'integer',
        'total_expected' => 'integer',
        'present_count' => 'integer',
        'late_count' => 'integer',
        'absent_count' => 'integer',
        'excused_count' => 'integer',
        'authorized_absence_count' => 'integer',
        'unknown_count' => 'integer',
        'attendance_rate' => 'decimal:2',
        'closed_by' => 'integer',
        'closed_at' => 'datetime',
        'summary' => 'array',
    ];

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }
}
