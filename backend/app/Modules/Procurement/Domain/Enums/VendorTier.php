<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Kelas/tier vendor (§6). */
enum VendorTier: string
{
    use EnumOptions;

    case TIER_1 = 'TIER_1';
    case TIER_2 = 'TIER_2';
    case TIER_3 = 'TIER_3';

    public function label(): string
    {
        return match ($this) {
            self::TIER_1 => 'Tier 1 — Utama',
            self::TIER_2 => 'Tier 2 — Menengah',
            self::TIER_3 => 'Tier 3 — Pelengkap',
        };
    }
}
