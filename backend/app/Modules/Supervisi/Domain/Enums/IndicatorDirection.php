<?php

namespace App\Modules\Supervisi\Domain\Enums;

/** Arah nilai angka: makin tinggi makin baik, atau sebaliknya. */
enum IndicatorDirection: string
{
    case HIGHER_BETTER = 'HIGHER_BETTER';
    case LOWER_BETTER = 'LOWER_BETTER';

    public function label(): string
    {
        return match ($this) {
            self::HIGHER_BETTER => 'Semakin tinggi semakin baik',
            self::LOWER_BETTER => 'Semakin rendah semakin baik',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
