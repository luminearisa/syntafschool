<?php

namespace App\Modules\Asset\Domain\Enums;

enum StockOpnameStatus: string
{
    case PLANNED = 'PLANNED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case COUNTING = 'COUNTING';
    case VERIFICATION = 'VERIFICATION';
    case APPROVAL = 'APPROVAL';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    public function isOpen(): bool
    {
        return ! in_array($this, [self::COMPLETED, self::CANCELLED], true);
    }
}
