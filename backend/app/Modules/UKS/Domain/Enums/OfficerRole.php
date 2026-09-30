<?php

namespace App\Modules\UKS\Domain\Enums;

enum OfficerRole: string
{
    case PETUGAS = 'PETUGAS';
    case KOORDINATOR = 'KOORDINATOR';
    case NAKES = 'NAKES';

    public function label(): string
    {
        return match ($this) {
            self::PETUGAS => 'Petugas UKS',
            self::KOORDINATOR => 'Koordinator UKS',
            self::NAKES => 'Tenaga Kesehatan',
        };
    }
}

