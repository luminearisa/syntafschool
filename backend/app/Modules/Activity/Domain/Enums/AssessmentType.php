<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum AssessmentType — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum AssessmentType: string
{
    case SKILL = 'SKILL';
    case PERFORMANCE = 'PERFORMANCE';
    case PARTICIPATION = 'PARTICIPATION';
    case PROJECT = 'PROJECT';
    case COMPETENCY = 'COMPETENCY';
    case BEHAVIOR = 'BEHAVIOR';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::SKILL => 'Keterampilan',
            self::PERFORMANCE => 'Penampilan',
            self::PARTICIPATION => 'Partisipasi',
            self::PROJECT => 'Proyek',
            self::COMPETENCY => 'Kompetensi',
            self::BEHAVIOR => 'Perilaku',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
