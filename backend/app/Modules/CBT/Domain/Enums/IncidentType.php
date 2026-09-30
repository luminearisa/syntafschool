<?php

namespace App\Modules\CBT\Domain\Enums;

final class IncidentType
{
    public const TAB_BLUR = 'tab_blur';

    public const FULLSCREEN_EXIT = 'fullscreen_exit';

    public const COPY_ATTEMPT = 'copy_attempt';

    public const HEARTBEAT_GAP = 'heartbeat_gap';

    public const TOKEN_REJECTED = 'token_rejected';

    public const MANUAL_FLAG = 'manual_flag';

    public const LATE_SUBMIT = 'late_submit';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::TAB_BLUR, self::FULLSCREEN_EXIT, self::COPY_ATTEMPT,
            self::HEARTBEAT_GAP, self::TOKEN_REJECTED, self::MANUAL_FLAG, self::LATE_SUBMIT,
        ];
    }
}
