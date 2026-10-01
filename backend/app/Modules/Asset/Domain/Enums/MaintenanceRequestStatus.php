<?php

namespace App\Modules\Asset\Domain\Enums;

enum MaintenanceRequestStatus: string
{
    case NEW = 'NEW';
    case TRIAGED = 'TRIAGED';
    case ASSIGNED = 'ASSIGNED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case WAITING_PARTS = 'WAITING_PARTS';
    case WAITING_VENDOR = 'WAITING_VENDOR';
    case COMPLETED = 'COMPLETED';
    case VERIFIED = 'VERIFIED';
    case CLOSED = 'CLOSED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';

    public function isOpen(): bool
    {
        return ! in_array($this, [self::CLOSED, self::REJECTED, self::CANCELLED], true);
    }
}
