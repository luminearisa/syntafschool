<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\GradeSyncStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak sinkronisasi nilai ke SIAKAD (§41–§42).
 *
 * `payload_hash` dipakai untuk idempotensi: pengiriman ulang dengan payload
 * identik tidak membuat entri/penilaian baru di SIAKAD.
 */
class LmsGradeSync extends BaseModel
{
    protected $table = 'lms_grade_syncs';

    protected $casts = [
        'status' => GradeSyncStatus::class,
        'payload' => 'array',
        'attempts' => 'integer',
        'last_attempt_at' => 'datetime',
        'synced_at' => 'datetime',
        'locked_at' => 'datetime',
        // Phase 5 — kolom antrean (§6): tanpa cast, nilai tanggal keluar sebagai
        // string mentah dan pembandingan/penjadwalan jadi tidak konsisten.
        'queued_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'compare_snapshot' => 'array',
    ];

    public function gradebook(): BelongsTo
    {
        return $this->belongsTo(LmsGradebook::class, 'gradebook_id');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
