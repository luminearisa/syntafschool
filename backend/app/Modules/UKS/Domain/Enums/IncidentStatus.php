<?php

namespace App\Modules\UKS\Domain\Enums;

enum IncidentStatus: string
{
    case TERBUKA = 'TERBUKA';
    case DITINDAKLANJUTI = 'DITINDAKLANJUTI';
    case SELESAI = 'SELESAI';

    public function label(): string
    {
        return match ($this) {
            self::TERBUKA => 'Terbuka',
            self::DITINDAKLANJUTI => 'Ditindaklanjuti',
            self::SELESAI => 'Selesai',
        };
    }
}

