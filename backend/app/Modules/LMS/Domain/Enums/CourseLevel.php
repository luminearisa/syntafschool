<?php

namespace App\Modules\LMS\Domain\Enums;

enum CourseLevel: string
{
    case BEGINNER = 'BEGINNER';
    case INTERMEDIATE = 'INTERMEDIATE';
    case ADVANCED = 'ADVANCED';
    case ALL_LEVELS = 'ALL_LEVELS';

    public function label(): string
    {
        return match ($this) {
            self::BEGINNER => 'Pemula',
            self::INTERMEDIATE => 'Menengah',
            self::ADVANCED => 'Mahir',
            self::ALL_LEVELS => 'Semua Tingkat',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
