<?php

namespace App\Modules\Asset\Domain\Enums;

enum WorkOrderStatus: string
{
    case DRAFT = 'DRAFT';
    case OPEN = 'OPEN';
    case ASSIGNED = 'ASSIGNED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case ON_HOLD = 'ON_HOLD';
    case COMPLETED = 'COMPLETED';
    case VERIFIED = 'VERIFIED';
    case CANCELLED = 'CANCELLED';

    public function isOpen(): bool
    {
        return in_array($this, [self::OPEN, self::ASSIGNED, self::IN_PROGRESS, self::ON_HOLD], true);
    }
}
