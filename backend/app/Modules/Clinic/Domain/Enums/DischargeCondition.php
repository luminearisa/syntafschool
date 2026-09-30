<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum DischargeCondition — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum DischargeCondition: string
{
    case RECOVERED = 'RECOVERED';
    case IMPROVING = 'IMPROVING';
    case STABLE = 'STABLE';
    case UNCHANGED = 'UNCHANGED';
    case WORSENED = 'WORSENED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::RECOVERED => 'Sembuh',
            self::IMPROVING => 'Membaik',
            self::STABLE => 'Stabil',
            self::UNCHANGED => 'Tidak Berubah',
            self::WORSENED => 'Memburuk',
        };
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
