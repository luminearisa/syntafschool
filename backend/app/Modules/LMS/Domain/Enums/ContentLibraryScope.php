<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Cakupan pustaka konten guru (§45).
 */
enum ContentLibraryScope: string
{
    case MINE = 'MINE';
    case INSTITUTION = 'INSTITUTION';

    public function label(): string
    {
        return match ($this) {
            self::MINE => 'Konten saya',
            self::INSTITUTION => 'Pustaka sekolah',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
