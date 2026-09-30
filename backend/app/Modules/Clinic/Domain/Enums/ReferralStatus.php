<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum ReferralStatus — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum ReferralStatus: string
{
    case PLANNED = 'PLANNED';
    case REFERRED = 'REFERRED';
    case IN_TRANSIT = 'IN_TRANSIT';
    case ARRIVED = 'ARRIVED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PLANNED => 'Direncanakan',
            self::REFERRED => 'Dirujuk',
            self::IN_TRANSIT => 'Dalam Perjalanan',
            self::ARRIVED => 'Tiba di Fasilitas',
            self::COMPLETED => 'Selesai',
            self::CANCELLED => 'Dibatalkan',
        };
    }


    public function isOpen(): bool
    {
        return in_array($this, [self::PLANNED, self::REFERRED, self::IN_TRANSIT, self::ARRIVED], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
