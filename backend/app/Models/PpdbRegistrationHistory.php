<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat perubahan pendaftaran (status, revisi, verifikasi, pembayaran).
 */
class PpdbRegistrationHistory extends BaseModel
{
    public function registration(): BelongsTo
    {
        return $this->belongsTo(PpdbRegistration::class, 'registration_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}