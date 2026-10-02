<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `religious_audit_logs`.
 */
class ReligiousAuditLog extends Model
{
    protected $table = 'religious_audit_logs';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    /** Tabel append-only: hanya `created_at`, tanpa `updated_at`. */
    public $timestamps = false;

    protected $casts = [
        'auditable_id' => 'integer',
        'user_id' => 'integer',
        'campus_id' => 'integer',
        'mosque_id' => 'integer',
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }
}
