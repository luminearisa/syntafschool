<?php

namespace App\Modules\Asset\Domain\Enums;

enum LoanStatus: string
{
    case REQUESTED = 'REQUESTED';
    case APPROVED = 'APPROVED';
    case RESERVED = 'RESERVED';
    case HANDED_OVER = 'HANDED_OVER';
    case ACTIVE = 'ACTIVE';
    case OVERDUE = 'OVERDUE';
    case RETURNED = 'RETURNED';
    case DAMAGED = 'DAMAGED';
    case LOST = 'LOST';
    case CANCELLED = 'CANCELLED';
    case REJECTED = 'REJECTED';

    /** Pinjaman yang masih memegang aset. */
    public function isOpen(): bool
    {
        return in_array($this, [self::HANDED_OVER, self::ACTIVE, self::OVERDUE, self::DAMAGED], true);
    }
}
