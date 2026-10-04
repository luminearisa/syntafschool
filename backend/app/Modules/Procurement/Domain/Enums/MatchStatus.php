<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Hasil 3-way matching (§29). */
enum MatchStatus: string
{
    use EnumOptions;

    case MATCHED = 'MATCHED';
    case PARTIALLY_MATCHED = 'PARTIALLY_MATCHED';
    case MISMATCH = 'MISMATCH';
    case EXCEPTION = 'EXCEPTION';

    public function label(): string
    {
        return match ($this) {
            self::MATCHED => 'Cocok',
            self::PARTIALLY_MATCHED => 'Cocok Sebagian',
            self::MISMATCH => 'Tidak Cocok',
            self::EXCEPTION => 'Pengecualian',
        };
    }

    /** Hanya MATCHED yang boleh langsung menuju Accounting. */
    public function isClean(): bool
    {
        return $this === self::MATCHED;
    }
}
