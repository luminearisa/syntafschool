<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum CertificateType — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum CertificateType: string
{
    case PARTICIPATION = 'PARTICIPATION';
    case COMPLETION = 'COMPLETION';
    case ACHIEVEMENT = 'ACHIEVEMENT';
    case LEADERSHIP = 'LEADERSHIP';
    case COMPETITION = 'COMPETITION';
    case TRAINING = 'TRAINING';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PARTICIPATION => 'Keikutsertaan',
            self::COMPLETION => 'Penyelesaian',
            self::ACHIEVEMENT => 'Prestasi',
            self::LEADERSHIP => 'Kepemimpinan',
            self::COMPETITION => 'Kompetisi',
            self::TRAINING => 'Pelatihan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
