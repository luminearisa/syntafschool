<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum SessionType — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum SessionType: string
{
    case REGULAR = 'REGULAR';
    case SPECIAL = 'SPECIAL';
    case HOLIDAY = 'HOLIDAY';
    case EXAM = 'EXAM';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::REGULAR => 'Rutin',
            self::SPECIAL => 'Khusus',
            self::HOLIDAY => 'Libur',
            self::EXAM => 'Ujian',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
