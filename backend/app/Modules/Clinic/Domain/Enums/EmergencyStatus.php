<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum EmergencyStatus — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum EmergencyStatus: string
{
    case OPEN = 'OPEN';
    case STABILIZED = 'STABILIZED';
    case REFERRED = 'REFERRED';
    case CLOSED = 'CLOSED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Terbuka',
            self::STABILIZED => 'Stabil',
            self::REFERRED => 'Dirujuk',
            self::CLOSED => 'Selesai',
        };
    }


    public function isOpen(): bool
    {
        return in_array($this, [self::OPEN, self::STABILIZED, self::REFERRED], true);
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
