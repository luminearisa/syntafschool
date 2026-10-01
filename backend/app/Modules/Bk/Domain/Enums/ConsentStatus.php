<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Status persetujuan.
 *
 * `APPROVED` dipertahankan sebagai alias baca untuk data Phase 1: baris lama yang
 * belum tersentuh migrasi tetap dikenali sebagai "diberikan", bukan jatuh ke status
 * tak dikenal. Penulisan baru selalu memakai `GRANTED`.
 */
enum ConsentStatus: string
{
    case REQUESTED = 'REQUESTED';
    case GRANTED = 'GRANTED';
    case DECLINED = 'DECLINED';
    case EXPIRED = 'EXPIRED';
    case REVOKED = 'REVOKED';
    case APPROVED = 'APPROVED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::REQUESTED => 'Diminta',
            self::GRANTED => 'Diberikan',
            self::DECLINED => 'Ditolak',
            self::EXPIRED => 'Kedaluwarsa',
            self::REVOKED => 'Dicabut',
            self::APPROVED => 'Diberikan (istilah lama)',
        };
    }

    /** Satu-satunya sumber kebenaran "izin ini berlaku". */
    public function isGranted(): bool
    {
        return in_array($this, [self::GRANTED, self::APPROVED], true);
    }

    public function isFinal(): bool
    {
        return $this !== self::REQUESTED;
    }

    /** Normalisasi ejaan lama saat menulis. */
    public function canonical(): self
    {
        return $this === self::APPROVED ? self::GRANTED : $this;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
