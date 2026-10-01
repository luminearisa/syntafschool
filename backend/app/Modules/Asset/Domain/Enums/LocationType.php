<?php

namespace App\Modules\Asset\Domain\Enums;

enum LocationType: string
{
    case CAMPUS = 'CAMPUS';
    case BUILDING = 'BUILDING';
    case FLOOR = 'FLOOR';
    case ROOM = 'ROOM';
    case CLASSROOM = 'CLASSROOM';
    case LAB = 'LAB';
    case OFFICE = 'OFFICE';
    case LIBRARY = 'LIBRARY';
    case WAREHOUSE = 'WAREHOUSE';
    case DORMITORY = 'DORMITORY';
    case HALL = 'HALL';
    case SPORT_FACILITY = 'SPORT_FACILITY';
    case KITCHEN = 'KITCHEN';
    case CLINIC = 'CLINIC';
    case MOSQUE = 'MOSQUE';
    case WORKSHOP = 'WORKSHOP';
    case PARKING = 'PARKING';
    case OUTDOOR_AREA = 'OUTDOOR_AREA';
    case OTHER = 'OTHER';

    /** Jenis yang boleh menampung aset secara langsung. */
    public function canHoldAssets(): bool
    {
        return ! in_array($this, [self::CAMPUS, self::BUILDING, self::FLOOR], true);
    }

    /** Tingkat hierarki: kampus > gedung > lantai > ruang. */
    public function level(): int
    {
        return match ($this) {
            self::CAMPUS => 1,
            self::BUILDING => 2,
            self::FLOOR => 3,
            default => 4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CAMPUS => 'Kampus',
            self::BUILDING => 'Gedung',
            self::FLOOR => 'Lantai',
            self::ROOM => 'Ruang',
            self::CLASSROOM => 'Ruang kelas',
            self::LAB => 'Laboratorium',
            self::OFFICE => 'Kantor',
            self::LIBRARY => 'Perpustakaan',
            self::WAREHOUSE => 'Gudang',
            self::DORMITORY => 'Asrama',
            self::HALL => 'Aula',
            self::SPORT_FACILITY => 'Sarana olahraga',
            self::KITCHEN => 'Dapur',
            self::CLINIC => 'Klinik',
            self::MOSQUE => 'Masjid',
            self::WORKSHOP => 'Bengkel',
            self::PARKING => 'Parkir',
            self::OUTDOOR_AREA => 'Area luar',
            self::OTHER => 'Lainnya',
        };
    }
}
