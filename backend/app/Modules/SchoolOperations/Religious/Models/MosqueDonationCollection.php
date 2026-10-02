<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `mosque_donation_collections`.
 */
class MosqueDonationCollection extends Model
{
    protected $table = 'mosque_donation_collections';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'mosque_id' => 'integer',
        'collection_date' => 'date',
        'opened_by' => 'integer',
        'closed_by' => 'integer',
        'verified_by' => 'integer',
        'amount' => 'decimal:2',
        'meta' => 'array',
    ];

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }
}
