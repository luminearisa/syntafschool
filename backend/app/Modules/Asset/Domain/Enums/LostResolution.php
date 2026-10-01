<?php

namespace App\Modules\Asset\Domain\Enums;

enum LostResolution: string
{
    case FOUND = 'FOUND';
    case REPLACED = 'REPLACED';
    case WRITTEN_OFF = 'WRITTEN_OFF';
    case RECOVERED_VALUE = 'RECOVERED_VALUE';
    case OTHER = 'OTHER';
}
