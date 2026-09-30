<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum MedicationRoute — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum MedicationRoute: string
{
    case ORAL = 'ORAL';
    case TOPICAL = 'TOPICAL';
    case OPHTHALMIC = 'OPHTHALMIC';
    case OTIC = 'OTIC';
    case NASAL = 'NASAL';
    case INJECTION = 'INJECTION';
    case OTHER = 'OTHER';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::ORAL => 'Oral',
            self::TOPICAL => 'Topikal',
            self::OPHTHALMIC => 'Mata',
            self::OTIC => 'Telinga',
            self::NASAL => 'Hidung',
            self::INJECTION => 'Injeksi',
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
