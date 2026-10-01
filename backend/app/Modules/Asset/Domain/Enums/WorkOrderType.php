<?php

namespace App\Modules\Asset\Domain\Enums;

enum WorkOrderType: string
{
    case PREVENTIVE = 'PREVENTIVE';
    case CORRECTIVE = 'CORRECTIVE';
    case EMERGENCY = 'EMERGENCY';
    case INSPECTION_FOLLOW_UP = 'INSPECTION_FOLLOW_UP';
    case IMPROVEMENT = 'IMPROVEMENT';
}
