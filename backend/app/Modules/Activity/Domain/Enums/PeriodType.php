<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum PeriodType — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum PeriodType: string
{
    case ACADEMIC_YEAR = 'ACADEMIC_YEAR';
    case SEMESTER = 'SEMESTER';
    case TERM = 'TERM';
    case SEASON = 'SEASON';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::ACADEMIC_YEAR => 'Tahun Ajaran',
            self::SEMESTER => 'Semester',
            self::TERM => 'Term',
            self::SEASON => 'Musim',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
