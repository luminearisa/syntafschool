<?php

namespace App\Modules\Asset\Domain\Enums;

enum IncidentStatus: string
{
    case REPORTED = 'REPORTED';
    case UNDER_INVESTIGATION = 'UNDER_INVESTIGATION';
    case RESOLVED = 'RESOLVED';
    case WRITTEN_OFF = 'WRITTEN_OFF';
    case CLOSED = 'CLOSED';
}
