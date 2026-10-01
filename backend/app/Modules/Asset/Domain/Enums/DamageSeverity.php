<?php

namespace App\Modules\Asset\Domain\Enums;

enum DamageSeverity: string
{
    case MINOR = 'MINOR';
    case MODERATE = 'MODERATE';
    case MAJOR = 'MAJOR';
    case TOTAL = 'TOTAL';

    public function rank(): int
    {
        return match ($this) {
            self::MINOR => 1,
            self::MODERATE => 2,
            self::MAJOR => 3,
            self::TOTAL => 4,
        };
    }

    public function priority(): MaintenancePriority
    {
        return match ($this) {
            self::MINOR => MaintenancePriority::LOW,
            self::MODERATE => MaintenancePriority::NORMAL,
            self::MAJOR => MaintenancePriority::HIGH,
            self::TOTAL => MaintenancePriority::CRITICAL,
        };
    }
}
