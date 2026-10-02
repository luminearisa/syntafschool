<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EventAuditLog — tabel `event_audit_logs`.
 *
 * Relasi hanya dibentuk untuk rujukan di dalam modul Event. Kolom yang
 * menunjuk modul lain diakses lewat kontrak/gateway, bukan relasi Eloquent.
 */
class EventAuditLog extends Model
{
    use SoftDeletes;

    /** Tabel yang dipetakan (disebut eksplisit agar aman terhadap bentuk jamak tak beraturan). */
    protected $table = 'event_audit_logs';
    use SoftDeletes;
    /** Kolom yang boleh diisi massal. */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'actor_id' => 'integer',
            'entity_id' => 'integer',
            'old_value' => 'array',
            'new_value' => 'array',
            'campus_id' => 'integer',
            'created_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
    /** Tabel append-only: hanya `created_at`, tanpa `updated_at`. */
    public $timestamps = false;
}
