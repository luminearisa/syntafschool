<?php

namespace App\Modules\Asset\Domain\Enums;

enum TaskStatus: string
{
    case PENDING = 'PENDING';
    case IN_PROGRESS = 'IN_PROGRESS';
    case DONE = 'DONE';
    case SKIPPED = 'SKIPPED';
}
