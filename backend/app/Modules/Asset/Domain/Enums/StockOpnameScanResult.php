<?php

namespace App\Modules\Asset\Domain\Enums;

enum StockOpnameScanResult: string
{
    case FOUND = 'FOUND';
    case MISSING = 'MISSING';
    case EXTRA = 'EXTRA';
    case WRONG_LOCATION = 'WRONG_LOCATION';
    case WRONG_CUSTODIAN = 'WRONG_CUSTODIAN';
    case DAMAGED = 'DAMAGED';
    case UNIDENTIFIED = 'UNIDENTIFIED';

    /** Hasil yang dianggap selisih. */
    public function isVariance(): bool
    {
        return $this !== self::FOUND;
    }
}
