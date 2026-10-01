<?php

namespace App\Modules\Asset\Domain\Enums;

enum AssignmentType: string
{
    case INDIVIDUAL = 'INDIVIDUAL';
    case DEPARTMENT = 'DEPARTMENT';
    case ROOM = 'ROOM';
    case PROJECT = 'PROJECT';
    case TEMPORARY = 'TEMPORARY';
    case PERMANENT = 'PERMANENT';
}
