<?php

namespace App\Modules\Asset\Domain\Enums;

enum TransferStatus: string
{
    case REQUESTED = 'REQUESTED';
    case APPROVED = 'APPROVED';
    case HANDED_OVER = 'HANDED_OVER';
    case COMPLETED = 'COMPLETED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';

    public function isOpen(): bool
    {
        return in_array($this, [self::REQUESTED, self::APPROVED, self::HANDED_OVER], true);
    }
}
