<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Prioritas permintaan pengadaan. */
enum PurchaseRequestPriority: string
{
    use EnumOptions;

    case LOW = 'LOW';
    case NORMAL = 'NORMAL';
    case HIGH = 'HIGH';
    case URGENT = 'URGENT';

    public function label(): string
    {
        return match ($this) {
            self::LOW => 'Rendah',
            self::NORMAL => 'Normal',
            self::HIGH => 'Tinggi',
            self::URGENT => 'Mendesak',
        };
    }
}
