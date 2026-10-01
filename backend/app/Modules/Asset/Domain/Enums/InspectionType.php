<?php

namespace App\Modules\Asset\Domain\Enums;

enum InspectionType: string
{
    case INITIAL = 'INITIAL';
    case ROUTINE = 'ROUTINE';
    case SAFETY = 'SAFETY';
    case PREVENTIVE = 'PREVENTIVE';
    case POST_MAINTENANCE = 'POST_MAINTENANCE';
    case PRE_DISPOSAL = 'PRE_DISPOSAL';
    case STOCK_OPNAME = 'STOCK_OPNAME';
    case RETURN = 'RETURN';
}
