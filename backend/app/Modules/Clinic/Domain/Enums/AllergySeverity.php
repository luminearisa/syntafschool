<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum AllergySeverity — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum AllergySeverity: string
{
    case MILD = 'MILD';
    case MODERATE = 'MODERATE';
    case SEVERE = 'SEVERE';
    case UNKNOWN = 'UNKNOWN';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::MILD => 'Ringan',
            self::MODERATE => 'Sedang',
            self::SEVERE => 'Berat',
            self::UNKNOWN => 'Belum Diketahui',
        };
    }


    /** Alergi berat wajib memunculkan peringatan mencolok. */
    public function isCritical(): bool
    {
        return $this === self::SEVERE;
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
