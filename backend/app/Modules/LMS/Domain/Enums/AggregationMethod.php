<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Metode agregasi nilai dalam satu kategori/gradebook (§8).
 */
enum AggregationMethod: string
{
    case WEIGHTED_MEAN = 'WEIGHTED_MEAN';
    case SIMPLE_MEAN = 'SIMPLE_MEAN';
    case SUM = 'SUM';
    case HIGHEST = 'HIGHEST';
    case LOWEST = 'LOWEST';
    case MEDIAN = 'MEDIAN';

    public function label(): string
    {
        return match ($this) {
            self::WEIGHTED_MEAN => 'Rata-rata berbobot',
            self::SIMPLE_MEAN => 'Rata-rata sederhana',
            self::SUM => 'Jumlah',
            self::HIGHEST => 'Nilai tertinggi',
            self::LOWEST => 'Nilai terendah',
            self::MEDIAN => 'Median',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::WEIGHTED_MEAN => 'Setiap item dikalikan bobotnya lalu dibagi total bobot.',
            self::SIMPLE_MEAN => 'Semua item dianggap berbobot sama.',
            self::SUM => 'Seluruh poin dijumlahkan (untuk gradebook berbasis poin).',
            self::HIGHEST => 'Hanya nilai terbaik yang dihitung.',
            self::LOWEST => 'Hanya nilai terendah yang dihitung (mis. kehadiran terburuk).',
            self::MEDIAN => 'Nilai tengah, tahan terhadap pencilan.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
