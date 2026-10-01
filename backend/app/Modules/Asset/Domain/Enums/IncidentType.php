<?php

namespace App\Modules\Asset\Domain\Enums;

enum IncidentType: string
{
    case DAMAGE = 'DAMAGE';
    case LOSS = 'LOSS';
    case THEFT = 'THEFT';
    case ACCIDENT = 'ACCIDENT';
    case MISUSE = 'MISUSE';
    case SAFETY = 'SAFETY';
    case ENVIRONMENTAL = 'ENVIRONMENTAL';
    case OTHER = 'OTHER';
}
