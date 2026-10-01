<?php

namespace App\Modules\Asset\Domain\Enums;

enum MaintenanceFrequency: string
{
    case DAYS_30 = 'DAYS_30';
    case DAYS_90 = 'DAYS_90';
    case DAYS_180 = 'DAYS_180';
    case DAYS_365 = 'DAYS_365';
    case USAGE_BASED = 'USAGE_BASED';
    case METER_BASED = 'METER_BASED';
    case CUSTOM = 'CUSTOM';

    /** Jarak hari untuk frekuensi tetap; null bila berbasis pemakaian/meter. */
    public function intervalDays(): ?int
    {
        return match ($this) {
            self::DAYS_30 => 30,
            self::DAYS_90 => 90,
            self::DAYS_180 => 180,
            self::DAYS_365 => 365,
            default => null,
        };
    }
}
