<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak interaksi payment gateway (§20, §66). Setiap charge yang dibuat dan
 * setiap webhook yang diterima dicatat di sini beserta payload ternormalisasi
 * dan kunci idempotensi — bukan entitas pembayaran baru, hanya log peristiwa
 * agar notifikasi ganda bisa dideteksi dan ditelusuri (§71).
 */
class FinancePaymentGatewayTransaction extends BaseModel
{
    public const EVENT_CHARGE = 'charge';
    public const EVENT_WEBHOOK = 'webhook';

    protected $casts = [
        'payment_id' => 'integer',
        'amount' => 'decimal:2',
        'payload' => 'array',
        'signature_valid' => 'boolean',
        'handled' => 'boolean',
        'created_by' => 'integer',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FinancePayment::class, 'payment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
