<?php

namespace App\Modules\Asset\Domain\Enums;

enum ReservationStatus: string
{
    case HELD = 'HELD';
    case CONFIRMED = 'CONFIRMED';
    case CONVERTED = 'CONVERTED';
    case EXPIRED = 'EXPIRED';
    case CANCELLED = 'CANCELLED';

    public function isHolding(): bool
    {
        return in_array($this, [self::HELD, self::CONFIRMED], true);
    }
}
