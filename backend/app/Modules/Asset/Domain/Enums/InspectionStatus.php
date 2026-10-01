<?php

namespace App\Modules\Asset\Domain\Enums;

enum InspectionStatus: string
{
    case SCHEDULED = 'SCHEDULED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case PASSED = 'PASSED';
    case PASSED_WITH_NOTES = 'PASSED_WITH_NOTES';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
}
