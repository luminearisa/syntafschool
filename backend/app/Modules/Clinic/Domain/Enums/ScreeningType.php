<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum ScreeningType — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum ScreeningType: string
{
    case GENERAL = 'GENERAL';
    case VISION = 'VISION';
    case HEARING = 'HEARING';
    case DENTAL = 'DENTAL';
    case NUTRITION = 'NUTRITION';
    case GROWTH = 'GROWTH';
    case OTHER = 'OTHER';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::GENERAL => 'Umum',
            self::VISION => 'Penglihatan',
            self::HEARING => 'Pendengaran',
            self::DENTAL => 'Gigi',
            self::NUTRITION => 'Gizi',
            self::GROWTH => 'Tumbuh Kembang',
            self::OTHER => 'Lainnya',
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
