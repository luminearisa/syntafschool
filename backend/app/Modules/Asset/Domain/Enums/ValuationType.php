<?php

namespace App\Modules\Asset\Domain\Enums;

enum ValuationType: string
{
    case ACQUISITION = 'ACQUISITION';
    case BOOK_VALUE = 'BOOK_VALUE';
    case RESIDUAL = 'RESIDUAL';
    case FAIR_VALUE = 'FAIR_VALUE';
    case REVALUATION = 'REVALUATION';
    case IMPAIRMENT = 'IMPAIRMENT';
}
