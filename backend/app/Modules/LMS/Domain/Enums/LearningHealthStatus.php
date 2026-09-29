<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Indikator kesehatan belajar (§60).
 *
 * Ini semata penanda aktivitas LMS — bukan diagnosis, bukan penilaian
 * kemampuan siswa.
 */
enum LearningHealthStatus: string
{
    case ON_TRACK = 'ON_TRACK';
    case AT_RISK = 'AT_RISK';
    case INACTIVE = 'INACTIVE';
    case COMPLETED = 'COMPLETED';

    public function label(): string
    {
        return match ($this) {
            self::ON_TRACK => 'Sesuai jalur',
            self::AT_RISK => 'Perlu perhatian',
            self::INACTIVE => 'Tidak aktif',
            self::COMPLETED => 'Selesai',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::ON_TRACK => 'green',
            self::AT_RISK => 'amber',
            self::INACTIVE => 'slate',
            self::COMPLETED => 'blue',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
