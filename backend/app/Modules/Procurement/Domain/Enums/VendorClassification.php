<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/**
 * Klasifikasi vendor (§6). Strategic/preferred memengaruhi aturan pemilihan;
 * restricted/blacklisted memblokir pemakaian kecuali ada override berizin.
 */
enum VendorClassification: string
{
    use EnumOptions;

    case NORMAL = 'NORMAL';
    case STRATEGIC = 'STRATEGIC';
    case PREFERRED = 'PREFERRED';
    case RESTRICTED = 'RESTRICTED';
    case BLACKLISTED = 'BLACKLISTED';

    public function label(): string
    {
        return match ($this) {
            self::NORMAL => 'Normal',
            self::STRATEGIC => 'Strategis',
            self::PREFERRED => 'Diutamakan',
            self::RESTRICTED => 'Dibatasi',
            self::BLACKLISTED => 'Daftar Hitam',
        };
    }

    /** Perlu override berizin sebelum dapat dipakai. */
    public function isRestricted(): bool
    {
        return in_array($this, [self::RESTRICTED, self::BLACKLISTED], true);
    }
}
