<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum ProgramType — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum ProgramType: string
{
    case EXTRACURRICULAR = 'EXTRACURRICULAR';
    case CLUB = 'CLUB';
    case ORGANIZATION = 'ORGANIZATION';
    case SPORT = 'SPORT';
    case ART = 'ART';
    case ACADEMIC = 'ACADEMIC';
    case RELIGIOUS = 'RELIGIOUS';
    case LEADERSHIP = 'LEADERSHIP';
    case COMMUNITY_SERVICE = 'COMMUNITY_SERVICE';
    case COMPETITION = 'COMPETITION';
    case OTHER = 'OTHER';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::EXTRACURRICULAR => 'Ekstrakurikuler',
            self::CLUB => 'Klub',
            self::ORGANIZATION => 'Organisasi',
            self::SPORT => 'Olahraga',
            self::ART => 'Seni',
            self::ACADEMIC => 'Akademik',
            self::RELIGIOUS => 'Keislaman',
            self::LEADERSHIP => 'Kepemimpinan',
            self::COMMUNITY_SERVICE => 'Pengabdian Masyarakat',
            self::COMPETITION => 'Kompetisi',
            self::OTHER => 'Lainnya',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
