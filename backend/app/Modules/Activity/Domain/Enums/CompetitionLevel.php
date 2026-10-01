<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum CompetitionLevel — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum CompetitionLevel: string
{
    case INTERNAL = 'INTERNAL';
    case SCHOOL = 'SCHOOL';
    case DISTRICT = 'DISTRICT';
    case CITY = 'CITY';
    case PROVINCE = 'PROVINCE';
    case NATIONAL = 'NATIONAL';
    case INTERNATIONAL = 'INTERNATIONAL';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::INTERNAL => 'Internal',
            self::SCHOOL => 'Sekolah',
            self::DISTRICT => 'Kecamatan',
            self::CITY => 'Kota/Kabupaten',
            self::PROVINCE => 'Provinsi',
            self::NATIONAL => 'Nasional',
            self::INTERNATIONAL => 'Internasional',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
