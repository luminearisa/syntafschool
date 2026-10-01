<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum AchievementType — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum AchievementType: string
{
    case ACADEMIC = 'ACADEMIC';
    case SPORT = 'SPORT';
    case ART = 'ART';
    case RELIGIOUS = 'RELIGIOUS';
    case LEADERSHIP = 'LEADERSHIP';
    case TECHNOLOGY = 'TECHNOLOGY';
    case COMMUNITY = 'COMMUNITY';
    case OTHER = 'OTHER';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::ACADEMIC => 'Akademik',
            self::SPORT => 'Olahraga',
            self::ART => 'Seni',
            self::RELIGIOUS => 'Keislaman',
            self::LEADERSHIP => 'Kepemimpinan',
            self::TECHNOLOGY => 'Teknologi',
            self::COMMUNITY => 'Sosial',
            self::OTHER => 'Lainnya',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
