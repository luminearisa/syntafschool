<?php

namespace App\Modules\Asset\Domain\Enums;

enum AssetAcquisitionSource: string
{
    case PURCHASE = 'PURCHASE';
    case DONATION = 'DONATION';
    case TRANSFER = 'TRANSFER';
    case CONSTRUCTION = 'CONSTRUCTION';
    case LEASE = 'LEASE';
    case FOUND = 'FOUND';
    case OTHER = 'OTHER';
}
