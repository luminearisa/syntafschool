<?php

namespace App\Modules\Asset\Domain\Enums;

enum DamageReportStatus: string
{
    case REPORTED = 'REPORTED';
    case VERIFIED = 'VERIFIED';
    case INSPECTED = 'INSPECTED';
    case MAINTENANCE_CREATED = 'MAINTENANCE_CREATED';
    case RESOLVED = 'RESOLVED';
    case REJECTED = 'REJECTED';
}
