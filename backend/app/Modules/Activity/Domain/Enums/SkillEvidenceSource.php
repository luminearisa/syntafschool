<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum SkillEvidenceSource — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum SkillEvidenceSource: string
{
    case ASSESSMENT = 'ASSESSMENT';
    case ACHIEVEMENT = 'ACHIEVEMENT';
    case PROJECT = 'PROJECT';
    case ATTENDANCE = 'ATTENDANCE';
    case LEADERSHIP = 'LEADERSHIP';
    case MANUAL = 'MANUAL';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::ASSESSMENT => 'Penilaian',
            self::ACHIEVEMENT => 'Prestasi',
            self::PROJECT => 'Proyek',
            self::ATTENDANCE => 'Kehadiran',
            self::LEADERSHIP => 'Kepemimpinan',
            self::MANUAL => 'Manual',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
