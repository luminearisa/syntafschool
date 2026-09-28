<?php

namespace App\Modules\LMS\Domain\Enums;

/** §12 — Learning item hanya dua jenis: konten pasif atau aktivitas. */
enum ItemType: string
{
    case RESOURCE = 'RESOURCE';
    case ACTIVITY = 'ACTIVITY';

    public function label(): string
    {
        return match ($this) {
            self::RESOURCE => 'Materi',
            self::ACTIVITY => 'Aktivitas',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
