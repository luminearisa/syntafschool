<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat perpanjangan pinjaman.
 *
 * Disimpan terpisah agar tenggat lama & baru terekam (jejak audit
 * perpanjangan), sementara pinjaman menyimpan jumlah perpanjangan.
 */
class LibraryLoanRenewal extends BaseModel
{
    protected $casts = [
        'previous_due_at' => 'date:Y-m-d',
        'new_due_at' => 'date:Y-m-d',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LibraryLoan::class, 'loan_id');
    }
}