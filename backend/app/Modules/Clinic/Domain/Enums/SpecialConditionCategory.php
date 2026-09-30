<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum SpecialConditionCategory — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum SpecialConditionCategory: string
{
    case CHRONIC_CONDITION = 'CHRONIC_CONDITION';
    case SPECIAL_CARE = 'SPECIAL_CARE';
    case DIETARY_RESTRICTION = 'DIETARY_RESTRICTION';
    case ACTIVITY_RESTRICTION = 'ACTIVITY_RESTRICTION';
    case EMERGENCY_INSTRUCTION = 'EMERGENCY_INSTRUCTION';
    case OTHER = 'OTHER';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::CHRONIC_CONDITION => 'Kondisi Kronis',
            self::SPECIAL_CARE => 'Perawatan Khusus',
            self::DIETARY_RESTRICTION => 'Pantangan Makanan',
            self::ACTIVITY_RESTRICTION => 'Pembatasan Aktivitas',
            self::EMERGENCY_INSTRUCTION => 'Instruksi Darurat',
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
