<?php

namespace App\Modules\Asset\Domain\Enums;

enum DisposalStatus: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case INSPECTED = 'INSPECTED';
    case VALUED = 'VALUED';
    case APPROVED = 'APPROVED';
    case EXECUTED = 'EXECUTED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';
    case RETIRED = 'RETIRED';
}
