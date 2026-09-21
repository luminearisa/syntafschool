<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris hasil stock opname.
 *
 * `item_id` kosong berarti hasil pindai di luar cakupan sesi (tak terduga).
 * `result` kosong berarti item diharapkan tetapi belum dipindai.
 */
class LibraryInventoryLine extends BaseModel
{
    public const RESULT_FOUND = 'found';
    public const RESULT_MISSING = 'missing';
    public const RESULT_UNEXPECTED = 'unexpected';
    public const RESULT_DAMAGED = 'damaged';

    public const RESULTS = [
        self::RESULT_FOUND, self::RESULT_MISSING, self::RESULT_UNEXPECTED, self::RESULT_DAMAGED,
    ];

    protected $casts = ['scanned_at' => 'datetime'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(LibraryInventorySession::class, 'session_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LibraryItem::class, 'item_id');
    }

    public function condition(): BelongsTo
    {
        return $this->belongsTo(LibraryCollectionCondition::class, 'condition_id');
    }
}