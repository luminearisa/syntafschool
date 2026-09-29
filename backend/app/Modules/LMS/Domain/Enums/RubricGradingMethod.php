<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Cara total rubric dihitung (§24).
 */
enum RubricGradingMethod: string
{
    case POINTS = 'POINTS';            // jumlah skor level
    case WEIGHTED = 'WEIGHTED';        // jumlah skor × bobot kriteria
    case PERCENTAGE = 'PERCENTAGE';    // total / maksimum × 100

    public function label(): string
    {
        return match ($this) {
            self::POINTS => 'Poin',
            self::WEIGHTED => 'Berbobot',
            self::PERCENTAGE => 'Persentase',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
