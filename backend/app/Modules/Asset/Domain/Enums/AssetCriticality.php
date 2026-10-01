<?php

namespace App\Modules\Asset\Domain\Enums;

enum AssetCriticality: string
{
    case LOW = 'LOW';
    case NORMAL = 'NORMAL';
    case HIGH = 'HIGH';
    case CRITICAL = 'CRITICAL';

    public function rank(): int
    {
        return match ($this) {
            self::LOW => 1,
            self::NORMAL => 2,
            self::HIGH => 3,
            self::CRITICAL => 4,
        };
    }

    /** Prioritas bawaan saat aset dengan kekritisan ini rusak. */
    public function defaultPriority(): MaintenancePriority
    {
        return match ($this) {
            self::CRITICAL => MaintenancePriority::URGENT,
            self::HIGH => MaintenancePriority::HIGH,
            self::NORMAL => MaintenancePriority::NORMAL,
            self::LOW => MaintenancePriority::LOW,
        };
    }
}
