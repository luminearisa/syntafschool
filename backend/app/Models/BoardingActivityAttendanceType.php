<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot konfigurasi "kegiatan ini boleh pakai tipe absensi apa saja" (§18).
 *
 * Tabelnya punya id sendiri (bukan pivot klasik `attach` tanpa model) supaya
 * form konfigurasi bisa menyunting barisnya seperti entri master — lengkap
 * dengan jejak audit. Urutan tampilan diambil dari `sort_order`.
 */
class BoardingActivityAttendanceType extends BaseModel
{
    use LogsBoardingActivity;

    /** Baris konfigurasi: tidak memakai created_at/updated_at. */
    public $timestamps = false;

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(BoardingActivity::class, 'activity_id');
    }

    public function attendanceType(): BelongsTo
    {
        return $this->belongsTo(BoardingAttendanceType::class, 'attendance_type_id');
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_ACTIVITY_ATTENDANCE_TYPE';
    }
}
