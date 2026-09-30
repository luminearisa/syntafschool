<?php

namespace App\Modules\CBT\Domain\Enums;

final class SessionStatus
{
    public const DRAFT = 'draft';

    public const SCHEDULED = 'scheduled';

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const ARCHIVED = 'archived';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::DRAFT, self::SCHEDULED, self::OPEN, self::CLOSED, self::ARCHIVED];
    }
}
