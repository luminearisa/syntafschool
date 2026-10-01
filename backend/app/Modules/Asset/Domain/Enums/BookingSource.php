<?php

namespace App\Modules\Asset\Domain\Enums;

enum BookingSource: string
{
    case ACADEMIC = 'ACADEMIC';
    case EVENT = 'EVENT';
    case STAFF = 'STAFF';
    case STUDENT_ACTIVITY = 'STUDENT_ACTIVITY';
    case BOARDING = 'BOARDING';
    case MANAGEMENT = 'MANAGEMENT';
    case OTHER = 'OTHER';
}
