<?php

namespace App\Modules\LMS\Domain\Enums;

enum BadgeAwardSource: string
{
    case AUTOMATIC = 'AUTOMATIC';
    case MANUAL = 'MANUAL';
    case IMPORT = 'IMPORT';

    public function label(): string
    {
        return match ($this) {
            self::AUTOMATIC => 'Otomatis',
            self::MANUAL => 'Manual',
            self::IMPORT => 'Impor',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
