<?php

namespace App\Modules\UKS\Domain\Enums;

enum ScreeningResult: string
{
    case NORMAL = 'NORMAL';
    case PERLU_PERHATIAN = 'PERLU_PERHATIAN';
    case RUJUK = 'RUJUK';

    public function label(): string
    {
        return match ($this) {
            self::NORMAL => 'Normal',
            self::PERLU_PERHATIAN => 'Perlu Perhatian',
            self::RUJUK => 'Perlu Rujukan',
        };
    }
}

