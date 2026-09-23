<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rekening sekolah yang dipakai modul wallet: tujuan setoran manual dan/atau
 * rekening pencairan withdrawal.
 *
 * Nama & kode bank diambil dari master `banks` yang sudah ada — tabel ini hanya
 * menambah nomor rekening, atas nama, dan kegunaannya (§28).
 */
class WalletBankAccount extends BaseModel
{
    public const PURPOSE_TOPUP = 'topup';

    public const PURPOSE_WITHDRAWAL = 'withdrawal';

    public const PURPOSE_BOTH = 'both';

    public const PURPOSES = [self::PURPOSE_TOPUP, self::PURPOSE_WITHDRAWAL, self::PURPOSE_BOTH];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    public function serves(string $purpose): bool
    {
        return $this->purpose === self::PURPOSE_BOTH || $this->purpose === $purpose;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForPurpose($query, string $purpose)
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->where('purpose', $purpose)->orWhere('purpose', self::PURPOSE_BOTH));
    }

    /** "BSI 1234567890 a.n. Syntaf School" — untuk ditampilkan di formulir top up. */
    public function label(): string
    {
        $bankName = $this->bank?->name ?? 'Bank';

        return "{$bankName} {$this->account_number} a.n. {$this->account_name}";
    }
}
