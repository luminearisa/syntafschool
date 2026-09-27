<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat tindakan admin atas payment `pending` (§22, §40). Kolom
 * `verified_by`/`reject_reason` di `FinancePayment` hanya menyimpan tindakan
 * terakhir; tabel ini menyimpan seluruh jejak (verifikasi, penolakan,
 * kedaluwarsa, pembatalan) sehingga alur persetujuan bisa diaudit.
 */
class FinancePaymentVerification extends BaseModel
{
    public const ACTION_VERIFIED = 'verified';
    public const ACTION_REJECTED = 'rejected';
    public const ACTION_EXPIRED = 'expired';
    public const ACTION_CANCELLED = 'cancelled';

    public const ACTIONS = [
        self::ACTION_VERIFIED,
        self::ACTION_REJECTED,
        self::ACTION_EXPIRED,
        self::ACTION_CANCELLED,
    ];

    protected $casts = [
        'payment_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FinancePayment::class, 'payment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
