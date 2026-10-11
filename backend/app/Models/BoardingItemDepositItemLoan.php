<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu siklus pinjam barang titipan: terbuka (returned_at null) → tertutup.
 *
 * Riwayat disimpan terpisah dari status barang agar peminjaman berulang
 * barang yang sama tetap telusur.
 */
class BoardingItemDepositItemLoan extends BaseModel
{
    protected $casts = [
        'borrowed_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(BoardingItemDepositItem::class, 'item_id');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function isOpen(): bool
    {
        return $this->returned_at === null;
    }
}
