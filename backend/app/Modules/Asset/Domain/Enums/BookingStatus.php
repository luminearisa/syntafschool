<?php

namespace App\Modules\Asset\Domain\Enums;

enum BookingStatus: string
{
    case REQUESTED = 'REQUESTED';
    case APPROVED = 'APPROVED';
    case RESERVED = 'RESERVED';
    case IN_USE = 'IN_USE';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
    case REJECTED = 'REJECTED';
    case NO_SHOW = 'NO_SHOW';

    /** Status yang masih menahan slot waktu. */
    public function holdsSlot(): bool
    {
        return in_array($this, [self::REQUESTED, self::APPROVED, self::RESERVED, self::IN_USE], true);
    }
}
