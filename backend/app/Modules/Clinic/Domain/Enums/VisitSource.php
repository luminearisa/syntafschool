<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum VisitSource — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum VisitSource: string
{
    case SELF = 'SELF';
    case TEACHER = 'TEACHER';
    case PARENT = 'PARENT';
    case BOARDING = 'BOARDING';
    case CLINIC = 'CLINIC';
    case EMERGENCY = 'EMERGENCY';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::SELF => 'Datang Sendiri',
            self::TEACHER => 'Guru',
            self::PARENT => 'Orang Tua',
            self::BOARDING => 'Asrama',
            self::CLINIC => 'Klinik',
            self::EMERGENCY => 'Gawat Darurat',
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
