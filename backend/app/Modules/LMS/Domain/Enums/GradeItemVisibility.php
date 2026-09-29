<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Visibilitas nilai sebuah item (§18, §32).
 */
enum GradeItemVisibility: string
{
    case VISIBLE = 'VISIBLE';                 // selalu tampil di nilai siswa
    case HIDDEN = 'HIDDEN';                   // hanya guru
    case RELEASED_ONLY = 'RELEASED_ONLY';     // tampil setelah dirilis

    public function label(): string
    {
        return match ($this) {
            self::VISIBLE => 'Terlihat',
            self::HIDDEN => 'Disembunyikan',
            self::RELEASED_ONLY => 'Setelah dirilis',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::VISIBLE => 'green',
            self::HIDDEN => 'red',
            self::RELEASED_ONLY => 'amber',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
