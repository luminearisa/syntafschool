<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EventIdempotencyKey — tabel `event_idempotency_keys`.
 *
 * Relasi hanya dibentuk untuk rujukan di dalam modul Event. Kolom yang
 * menunjuk modul lain diakses lewat kontrak/gateway, bukan relasi Eloquent.
 */
class EventIdempotencyKey extends Model
{
    use SoftDeletes;

    /** Tabel yang dipetakan (disebut eksplisit agar aman terhadap bentuk jamak tak beraturan). */
    protected $table = 'event_idempotency_keys';
    use SoftDeletes;
    /** Kolom yang boleh diisi massal. */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
    /** Tabel append-only: hanya `created_at`, tanpa `updated_at`. */
    public $timestamps = false;
}
