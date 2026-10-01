<?php

namespace App\Modules\Asset\Domain\Enums;

enum InspectionItemResult: string
{
    case PASS = 'PASS';
    case FAIL = 'FAIL';
    case NOT_APPLICABLE = 'NOT_APPLICABLE';
}
