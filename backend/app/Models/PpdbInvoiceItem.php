<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rincian tagihan. Nama & nominal disalin dari biaya agar tagihan lama tetap
 * terbaca walau konfigurasi biaya diubah kemudian.
 */
class PpdbInvoiceItem extends BaseModel
{
    protected $casts = ['amount' => 'decimal:2'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PpdbInvoice::class, 'invoice_id');
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(PpdbRegistrationFee::class, 'fee_id');
    }
}