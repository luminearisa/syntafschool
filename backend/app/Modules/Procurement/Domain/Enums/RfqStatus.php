<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Status RFQ (§12). */
enum RfqStatus: string
{
    use EnumOptions;

    case DRAFT = 'DRAFT';
    case ISSUED = 'ISSUED';
    case OPEN = 'OPEN';
    case PARTIALLY_RESPONDED = 'PARTIALLY_RESPONDED';
    case CLOSED = 'CLOSED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::ISSUED => 'Diterbitkan',
            self::OPEN => 'Terbuka',
            self::PARTIALLY_RESPONDED => 'Sebagian Direspons',
            self::CLOSED => 'Ditutup',
            self::CANCELLED => 'Dibatalkan',
        };
    }
}
