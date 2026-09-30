<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum ClinicType — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum ClinicType: string
{
    case UKS = 'UKS';
    case CLINIC = 'CLINIC';
    case BOARDING_CLINIC = 'BOARDING_CLINIC';
    case OTHER = 'OTHER';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::UKS => 'UKS',
            self::CLINIC => 'Klinik',
            self::BOARDING_CLINIC => 'Klinik Asrama',
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
