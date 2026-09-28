<?php

namespace App\Modules\LMS\Domain\Enums;

/** Strategi pengambilan nilai akhir dari beberapa percobaan (§30). */
enum QuizGradeStrategy: string
{
    case HIGHEST = 'HIGHEST';
    case LATEST = 'LATEST';
    case AVERAGE = 'AVERAGE';
    case FIRST = 'FIRST';

    public function label(): string
    {
        return match ($this) {
            self::HIGHEST => 'Nilai tertinggi',
            self::LATEST => 'Percobaan terakhir',
            self::AVERAGE => 'Rata-rata',
            self::FIRST => 'Percobaan pertama',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
