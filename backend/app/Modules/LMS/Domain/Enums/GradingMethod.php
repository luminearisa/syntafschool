<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Cara gradebook/grade item disajikan (§5). Perhitungan internal selalu
 * numerik; LETTER hanya lapisan tampilan hasil skala nilai.
 */
enum GradingMethod: string
{
    case RAW = 'RAW';
    case PERCENTAGE = 'PERCENTAGE';
    case LETTER = 'LETTER';
    case POINTS = 'POINTS';

    public function label(): string
    {
        return match ($this) {
            self::RAW => 'Nilai mentah',
            self::PERCENTAGE => 'Persentase',
            self::LETTER => 'Huruf',
            self::POINTS => 'Poin',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::RAW => 'gray',
            self::PERCENTAGE => 'brand',
            self::LETTER => 'blue',
            self::POINTS => 'green',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
