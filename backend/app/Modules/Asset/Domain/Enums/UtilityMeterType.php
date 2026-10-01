<?php

namespace App\Modules\Asset\Domain\Enums;

enum UtilityMeterType: string
{
    case ELECTRICITY = 'ELECTRICITY';
    case WATER = 'WATER';
    case GAS = 'GAS';
    case INTERNET = 'INTERNET';
    case OTHER = 'OTHER';
}
