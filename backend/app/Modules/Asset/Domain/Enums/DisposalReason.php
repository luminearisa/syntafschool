<?php

namespace App\Modules\Asset\Domain\Enums;

enum DisposalReason: string
{
    case DAMAGED = 'DAMAGED';
    case OBSOLETE = 'OBSOLETE';
    case UNECONOMICAL_REPAIR = 'UNECONOMICAL_REPAIR';
    case LOST = 'LOST';
    case SOLD = 'SOLD';
    case DONATED = 'DONATED';
    case SCRAPPED = 'SCRAPPED';
    case TRANSFERRED = 'TRANSFERRED';
    case OTHER = 'OTHER';
}
