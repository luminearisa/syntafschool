<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Normalisasi bobot (§7). STRICT menolak total bobot ≠ 100%; NORMALIZE
 * membagi dengan total bobot aktual sehingga guru tidak wajib menghitung persen.
 */
enum WeightPolicy: string
{
    case STRICT = 'STRICT';
    case NORMALIZE = 'NORMALIZE';

    public function label(): string
    {
        return match ($this) {
            self::STRICT => 'Wajib total 100%',
            self::NORMALIZE => 'Normalisasi otomatis',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::STRICT => 'Total bobot harus tepat 100 sebelum nilai boleh dirilis.',
            self::NORMALIZE => 'Total bobot apa pun dibagi dengan totalnya sehingga proporsi tetap benar.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
