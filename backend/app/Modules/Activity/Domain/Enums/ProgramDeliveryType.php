<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum ProgramDeliveryType — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum ProgramDeliveryType: string
{
    case RECURRING = 'RECURRING';
    case SEASONAL = 'SEASONAL';
    case ONE_TIME = 'ONE_TIME';
    case PROJECT_BASED = 'PROJECT_BASED';
    case COMPETITIVE = 'COMPETITIVE';
    case ORGANIZATION = 'ORGANIZATION';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::RECURRING => 'Rutin',
            self::SEASONAL => 'Musiman',
            self::ONE_TIME => 'Sekali Kegiatan',
            self::PROJECT_BASED => 'Berbasis Proyek',
            self::COMPETITIVE => 'Kompetitif',
            self::ORGANIZATION => 'Organisasi',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
