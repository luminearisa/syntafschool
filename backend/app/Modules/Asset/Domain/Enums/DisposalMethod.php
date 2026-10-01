<?php

namespace App\Modules\Asset\Domain\Enums;

enum DisposalMethod: string
{
    case SALE = 'SALE';
    case DONATION = 'DONATION';
    case SCRAP = 'SCRAP';
    case WRITE_OFF = 'WRITE_OFF';
    case TRANSFER = 'TRANSFER';
    case RETURN_TO_VENDOR = 'RETURN_TO_VENDOR';
    case OTHER = 'OTHER';
}
