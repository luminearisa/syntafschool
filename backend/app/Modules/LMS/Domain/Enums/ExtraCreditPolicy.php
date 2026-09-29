<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Kebijakan extra credit (§11). Nilai akhir tidak pernah melebihi
 * `maximum_grade` bila policy CAP.
 */
enum ExtraCreditPolicy: string
{
    case CAP = 'CAP';
    case UNCAPPED = 'UNCAPPED';
    case IGNORE = 'IGNORE';

    public function label(): string
    {
        return match ($this) {
            self::CAP => 'Dibatasi nilai maksimum',
            self::UNCAPPED => 'Tanpa batas',
            self::IGNORE => 'Diabaikan',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CAP => 'Contoh: 100 + 5 tetap 100.',
            self::UNCAPPED => 'Contoh: 100 + 5 menjadi 105.',
            self::IGNORE => 'Item bertanda extra credit tidak memengaruhi nilai akhir.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
