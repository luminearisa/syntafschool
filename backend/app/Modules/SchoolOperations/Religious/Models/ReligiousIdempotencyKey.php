<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model tabel `religious_idempotency_keys`.
 */
class ReligiousIdempotencyKey extends Model
{
    protected $table = 'religious_idempotency_keys';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    /** Tabel append-only: hanya `created_at`, tanpa `updated_at`. */
    public $timestamps = false;

    protected $casts = [
        'response' => 'array',
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
