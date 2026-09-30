<?php

namespace App\Modules\UKS\Domain\Enums;

enum VisitType: string
{
    case KELUHAN = 'KELUHAN';
    case KECELAKAAN = 'KECELAKAAN';
    case RUJUKAN_GURU = 'RUJUKAN_GURU';
    case KONTROL = 'KONTROL';

    public function label(): string
    {
        return match ($this) {
            self::KELUHAN => 'Keluhan/Sakit',
            self::KECELAKAAN => 'Kecelakaan',
            self::RUJUKAN_GURU => 'Rujukan Guru',
            self::KONTROL => 'Kontrol Ulang',
        };
    }
}

