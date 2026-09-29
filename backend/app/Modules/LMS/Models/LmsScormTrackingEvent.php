<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak pelacakan SCORM (§12, §41).
 *
 * `idempotency_key` unik menahan elemen yang sama dilaporkan dua kali
 * (commit berulang dari peluncur, koneksi terputus lalu terkirim ulang).
 */
class LmsScormTrackingEvent extends BaseModel
{
    protected $table = 'lms_scorm_tracking_events';

    protected $casts = ['occurred_at' => 'datetime'];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(LmsScormAttempt::class, 'attempt_id');
    }
}
