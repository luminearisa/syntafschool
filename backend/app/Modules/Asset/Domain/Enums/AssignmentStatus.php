<?php

namespace App\Modules\Asset\Domain\Enums;

enum AssignmentStatus: string
{
    case REQUESTED = 'REQUESTED';
    case APPROVED = 'APPROVED';
    case ACTIVE = 'ACTIVE';
    case ACKNOWLEDGED = 'ACKNOWLEDGED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
}
