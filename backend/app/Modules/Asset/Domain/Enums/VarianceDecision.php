<?php

namespace App\Modules\Asset\Domain\Enums;

enum VarianceDecision: string
{
    case PENDING = 'PENDING';
    case ACCEPTED = 'ACCEPTED';
    case INVESTIGATED = 'INVESTIGATED';
    case ADJUSTED = 'ADJUSTED';
    case REJECTED = 'REJECTED';
}
