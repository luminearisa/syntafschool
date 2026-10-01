<?php

namespace App\Modules\Asset\Domain\Enums;

enum OperationalStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case UNDER_CONSTRUCTION = 'UNDER_CONSTRUCTION';
    case RENOVATION = 'RENOVATION';
}
