<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Kelompok umur kasus (case aging).
 *
 * Ambangnya dibaca dari konfigurasi (kunci bk.aging), bukan ditulis di sini,
 * sehingga sekolah dapat menyesuaikan tanpa deploy. Dipakai untuk pemantauan
 * operasional, bukan penilaian otomatis atas kualitas konselor.
 */
enum CaseAgingBucket: string
{
    case DAYS_0_7 = 'DAYS_0_7';
    case DAYS_8_30 = 'DAYS_8_30';
    case DAYS_31_60 = 'DAYS_31_60';
    case DAYS_61_90 = 'DAYS_61_90';
    case DAYS_90_PLUS = 'DAYS_90_PLUS';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::DAYS_0_7 => '0-7 hari',
            self::DAYS_8_30 => '8-30 hari',
            self::DAYS_31_60 => '31-60 hari',
            self::DAYS_61_90 => '61-90 hari',
            self::DAYS_90_PLUS => 'lebih dari 90 hari',
        };
    }

    /** Batas atas bawaan (hari); null berarti tanpa batas. */
    public function defaultUpperBound(): ?int
    {
        return match ($this) {
            self::DAYS_0_7 => 7,
            self::DAYS_8_30 => 30,
            self::DAYS_31_60 => 60,
            self::DAYS_61_90 => 90,
            self::DAYS_90_PLUS => null,
        };
    }

    /** Batas dari konfigurasi bila tersedia, jika tidak memakai bawaan. */
    public function upperBound(): ?int
    {
        $configured = config('bk.aging.'.$this->value);

        return is_numeric($configured) ? (int) $configured : $this->defaultUpperBound();
    }

    /** Kelompok mana yang memuat umur kasus tertentu. */
    public static function forAge(int $days): self
    {
        foreach ([self::DAYS_0_7, self::DAYS_8_30, self::DAYS_31_60, self::DAYS_61_90] as $bucket) {
            $bound = $bucket->upperBound();

            if ($bound !== null && $days <= $bound) {
                return $bucket;
            }
        }

        return self::DAYS_90_PLUS;
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
