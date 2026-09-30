<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum ProcedureCategory — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum ProcedureCategory: string
{
    case BASIC_CARE = 'BASIC_CARE';
    case WOUND_CARE = 'WOUND_CARE';
    case MONITORING = 'MONITORING';
    case DIAGNOSTIC = 'DIAGNOSTIC';
    case OTHER = 'OTHER';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::BASIC_CARE => 'Perawatan Dasar',
            self::WOUND_CARE => 'Perawatan Luka',
            self::MONITORING => 'Pemantauan',
            self::DIAGNOSTIC => 'Diagnostik',
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
