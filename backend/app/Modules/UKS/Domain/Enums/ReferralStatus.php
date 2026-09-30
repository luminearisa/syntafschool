<?php

namespace App\Modules\UKS\Domain\Enums;

enum ReferralStatus: string
{
    case DIAJUKAN = 'DIAJUKAN';
    case BERANGKAT = 'BERANGKAT';
    case SELESAI = 'SELESAI';
    case DIBATALKAN = 'DIBATALKAN';

    public function label(): string
    {
        return match ($this) {
            self::DIAJUKAN => 'Diajukan',
            self::BERANGKAT => 'Berangkat',
            self::SELESAI => 'Selesai',
            self::DIBATALKAN => 'Dibatalkan',
        };
    }
}

