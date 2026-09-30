<?php

namespace App\Modules\CBT\Domain\Enums;

final class AttemptStatus
{
    public const NOT_STARTED = 'not_started';

    public const IN_PROGRESS = 'in_progress';

    public const SUBMITTED = 'submitted';

    public const EXPIRED = 'expired';

    public const GRADED = 'graded';

    public const VOIDED = 'voided';

    /** @return list<string> */
    public static function terminal(): array
    {
        return [self::SUBMITTED, self::EXPIRED, self::GRADED, self::VOIDED];
    }
}
