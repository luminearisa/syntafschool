<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EventDomainEvent — tabel `event_domain_events`.
 *
 * Relasi hanya dibentuk untuk rujukan di dalam modul Event. Kolom yang
 * menunjuk modul lain diakses lewat kontrak/gateway, bukan relasi Eloquent.
 */
class EventDomainEvent extends Model
{
    use SoftDeletes;

    /** Tabel yang dipetakan (disebut eksplisit agar aman terhadap bentuk jamak tak beraturan). */
    protected $table = 'event_domain_events';
    use SoftDeletes;
    /** Kolom yang boleh diisi massal. */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'aggregate_id' => 'integer',
            'payload' => 'array',
            'channels' => 'array',
            'published' => 'boolean',
            'published_at' => 'datetime',
            'consumer_count' => 'integer',
            'created_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
    /** Tabel append-only: hanya `created_at`, tanpa `updated_at`. */
    public $timestamps = false;
}
