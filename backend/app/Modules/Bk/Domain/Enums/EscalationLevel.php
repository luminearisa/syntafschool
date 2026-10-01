<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Tingkat eskalasi kasus.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum EscalationLevel: string
{
    case NONE = 'NONE';
    case COORDINATOR = 'COORDINATOR';
    case MANAGEMENT = 'MANAGEMENT';
    case EXTERNAL = 'EXTERNAL';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::NONE => 'Tidak ada',
            self::COORDINATOR => 'Koordinator BK',
            self::MANAGEMENT => 'Manajemen sekolah',
            self::EXTERNAL => 'Pihak luar/ahli',
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
