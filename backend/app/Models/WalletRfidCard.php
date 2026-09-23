<?php

namespace App\Models;

use App\Models\Concerns\LogsWalletActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kartu RFID fisik yang menunjuk ke satu wallet.
 *
 * Kartu adalah identitas fisik, bukan identitas saldo: menggantinya tidak boleh
 * membuat wallet baru atau memindahkan saldo (§6). Kartu lama dibiarkan ada
 * dengan status `lost`/`blocked`/`replaced` dan menunjuk kartu penggantinya,
 * supaya riwayat kartu tidak pernah hilang.
 *
 * Pada fase ini RFID hanya dipakai mengidentifikasi wallet; transaksi pembelian
 * (kantin/POS) sengaja belum dibangun.
 */
class WalletRfidCard extends BaseModel
{
    use LogsWalletActivity;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_LOST = 'lost';

    public const STATUS_REPLACED = 'replaced';

    public const STATUSES = [
        self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_BLOCKED,
        self::STATUS_LOST, self::STATUS_REPLACED,
    ];

    /** Status yang tidak boleh dipakai lagi untuk identifikasi. */
    public const DEAD_STATUSES = [
        self::STATUS_BLOCKED, self::STATUS_LOST, self::STATUS_REPLACED,
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_card_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * UID tidak pernah dipakai dua wallet aktif — cek global, bukan per wallet.
     */
    public static function uidInUse(string $uid, ?int $ignoreCardId = null): bool
    {
        return self::query()
            ->where('uid', $uid)
            ->when($ignoreCardId !== null, fn ($q) => $q->whereKeyNot($ignoreCardId))
            ->exists();
    }

    public function activityEventPrefix(): string
    {
        return 'WALLET_RFID';
    }
}
