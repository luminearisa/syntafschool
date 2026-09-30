<?php

namespace App\Modules\CBT\Domain\Enums;

final class QuestionType
{
    public const MULTIPLE_CHOICE = 'multiple_choice';

    public const MULTI_SELECT = 'multi_select';

    public const TRUE_FALSE = 'true_false';

    public const SHORT_ANSWER = 'short_answer';

    public const NUMERIC = 'numeric';

    public const MATCHING = 'matching';

    public const ORDERING = 'ordering';

    public const ESSAY = 'essay';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::MULTIPLE_CHOICE, self::MULTI_SELECT, self::TRUE_FALSE,
            self::SHORT_ANSWER, self::NUMERIC, self::MATCHING, self::ORDERING, self::ESSAY,
        ];
    }

    public static function autoGraded(string $type): bool
    {
        return $type !== self::ESSAY;
    }
}
