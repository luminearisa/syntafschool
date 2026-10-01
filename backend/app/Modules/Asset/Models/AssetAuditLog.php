<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Jejak audit modul — IMMUTABLE.
 *
 * Baris dapat dibuat, tetapi tidak pernah diubah atau dihapus: `saving`/`deleting`
 * pada model menolak perubahan atas baris yang sudah ada. Jejak yang dapat diedit
 * bukan jejak audit.
 */
class AssetAuditLog extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_audit_logs';

    /**
     * Jejak audit tidak dapat diubah dan tidak dapat dihapus.
     *
     * Penjagaan ini ada di model, bukan hanya di kebijakan: satu baris yang sudah
     * tercatat harus tetap sama pada setiap pembacaan berikutnya — termasuk bila
     * ada kode baru yang (tanpa sengaja) mencoba memperbaikinya.
     */
    protected static function booted(): void
    {
        static::updating(function (): bool {
            return false;
        });

        static::deleting(function (): bool {
            return false;
        });
    }

    protected $fillable = [
        'action',
        'subject_type',
        'subject_id',
        'asset_id',
        'facility_id',
        'actor_id',
        'actor_role',
        'status_before',
        'status_after',
        'from_location',
        'to_location',
        'reason',
        'before',
        'after',
        'context',
        'ip_address',
        'user_agent',
        'occurred_at',
    ];

    protected $casts = [
        'action' => \App\Modules\Asset\Domain\Enums\AuditAction::class,
        'before' => 'array',
        'after' => 'array',
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function actor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'actor_id');
    }

    public function asset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
