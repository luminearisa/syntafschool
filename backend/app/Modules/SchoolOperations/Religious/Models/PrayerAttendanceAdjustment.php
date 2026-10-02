<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `prayer_attendance_adjustments`.
 */
class PrayerAttendanceAdjustment extends Model
{
    protected $table = 'prayer_attendance_adjustments';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'attendance_id' => 'integer',
        'student_id' => 'integer',
        'employee_id' => 'integer',
        'attendance_date' => 'date',
        'adjusted_by' => 'integer',
        'adjusted_at' => 'datetime',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'evidence_document_id' => 'integer',
        'is_authorized' => 'boolean',
    ];

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(PrayerAttendanceRecord::class, 'attendance_id');
    }
}
