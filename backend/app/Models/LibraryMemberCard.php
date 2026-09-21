<?php

namespace App\Models;

use App\Models\Concerns\LogsLibraryActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kartu anggota.
 *
 * `qr_token` dipakai untuk sirkulasi/verifikasi pada Fase 4 — dibangkitkan
 * server-side dan tidak boleh diisi dari luar. Kartu dapat dicetak berulang
 * (dihitung pada `printed_count`) tanpa mengubah nomor kartu.
 */
class LibraryMemberCard extends BaseModel
{
    use LogsLibraryActivity;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_REVOKED, self::STATUS_EXPIRED];

    protected $casts = [
        'issued_at' => 'date:Y-m-d',
        'valid_until' => 'date:Y-m-d',
        'printed_count' => 'integer',
        'last_printed_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(LibraryMember::class, 'member_id');
    }

    public function isValid(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && ($this->valid_until === null || $this->valid_until->isFuture());
    }

    public function activityEventPrefix(): string
    {
        return 'CARD';
    }
}