<?php

namespace App\Modules\Asset\Domain\Enums;

enum AssetTagStatus: string
{
    case PENDING_TAGGING = 'PENDING_TAGGING';
    case TAGGED = 'TAGGED';
    case TAG_DAMAGED = 'TAG_DAMAGED';
    case TAG_REPLACEMENT = 'TAG_REPLACEMENT';
}
