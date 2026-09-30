<?php

namespace App\Modules\CBT\Domain\Enums;

final class Difficulty
{
    public const EASY = 'easy';

    public const MEDIUM = 'medium';

    public const HARD = 'hard';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::EASY, self::MEDIUM, self::HARD];
    }
}
