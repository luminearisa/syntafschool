<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dompet satu pemilik: santri, karyawan, orang tua/wali, atau pengguna.
 *
 * Pemilik disimpan sebagai empat FK nullable yang saling lepas (pola yang sama
 * dengan `library_members`), bukan kolom `owner_type` bebas — supaya tetap bisa
 * dirujuk balik ke master person yang sudah ada dan tidak pernah menunjuk baris
 * yang tidak ada. Tepat satu pemilik ditegakkan di `WalletService`.
 *
 * `balance` dan `held_balance` tidak boleh diubah langsung dari luar
 * `WalletLedgerService`.
 */
class Wallet extends BaseModel
{
    use Concerns\LogsWalletActivity;

    public const TYPE_STUDENT = 'student';

    public const TYPE_EMPLOYEE = 'employee';

    public const TYPE_GUARDIAN = 'guardian';

    public const TYPE_USER = 'user';

    public const OWNER_TYPES = [
        self::TYPE_STUDENT, self::TYPE_EMPLOYEE, self::TYPE_GUARDIAN, self::TYPE_USER,
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_BLOCKED, self::STATUS_CLOSED];

    /** Kolom FK per tipe pemilik. */
    public const OWNER_COLUMNS = [
        self::TYPE_STUDENT => 'student_id',
        self::TYPE_EMPLOYEE => 'employee_id',
        self::TYPE_GUARDIAN => 'guardian_id',
        self::TYPE_USER => 'user_id',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'held_balance' => 'decimal:2',
        'opened_at' => 'datetime',
        'blocked_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->latest('id');
    }

    public function topups(): HasMany
    {
        return $this->hasMany(WalletTopUp::class)->latest('id');
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(WalletWithdrawal::class)->latest('id');
    }

    public function rfidCards(): HasMany
    {
        return $this->hasMany(WalletRfidCard::class)->latest('id');
    }

    /**
     * Kartu aktif terakhir wallet ini, atau null bila belum ada / semua mati.
     */
    public function activeRfidCard(): ?WalletRfidCard
    {
        if (! $this->relationLoaded('rfidCards')) {
            return $this->rfidCards()
                ->where('status', WalletRfidCard::STATUS_ACTIVE)
                ->latest('id')
                ->first();
        }

        return $this->rfidCards
            ->where('status', WalletRfidCard::STATUS_ACTIVE)
            ->sortByDesc('id')
            ->first();
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(WalletAdjustment::class)->latest('id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(WalletReversal::class)->latest('id');
    }

    /**
     * Tipe pemilik wallet ini, atau null bila referensinya lepas (pemilik dihapus).
     */
    public function ownerType(): ?string
    {
        foreach (self::OWNER_COLUMNS as $type => $column) {
            if ($this->getAttribute($column) !== null) {
                return $type;
            }
        }

        return null;
    }

    public function owner(): ?BaseModel
    {
        $type = $this->ownerType();

        return $type === null ? null : $this->getRelation($type);
    }

    /**
     * Nama pemilik untuk daftar & laporan — tanpa memanggil relasi bila sudah ada.
     */
    public function ownerName(): string
    {
        $owner = $this->owner();

        if ($owner === null) {
            return '(tanpa pemilik)';
        }

        return (string) ($owner->full_name ?? $owner->name ?? $owner->getAttribute('email'));
    }

    /**
     * Identitas alami pemilik: NIS santri, NIP karyawan, email/ID orang tua.
     */
    public function ownerIdentifier(): ?string
    {
        $owner = $this->owner();

        if ($owner === null) {
            return null;
        }

        return match ($this->ownerType()) {
            self::TYPE_STUDENT => $owner->nis,
            self::TYPE_EMPLOYEE => $owner->nip,
            self::TYPE_USER => $owner->username ?? $owner->email,
            default => $owner->email ?? $owner->phone,
        };
    }

    /**
     * Saldo yang boleh dipakai/ditarik: saldo riil dikurangi dana tertahan.
     */
    public function availableBalance(): float
    {
        return round((float) $this->balance - (float) $this->held_balance, 2);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Wallet blocked/closed tidak boleh dipakai top up maupun withdrawal (§21).
     */
    public function canTransact(): bool
    {
        return $this->isActive();
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeOfType($query, ?string $type)
    {
        if ($type === null || ! isset(self::OWNER_COLUMNS[$type])) {
            return $query;
        }

        return $query->whereNotNull(self::OWNER_COLUMNS[$type]);
    }

    public function activityEventPrefix(): string
    {
        return 'WALLET';
    }
}
