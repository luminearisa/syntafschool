<?php

namespace App\Modules\LMS\Domain\Enums;

enum LiveSessionType: string
{
    case LIVE_CLASS = 'LIVE_CLASS';
    case OFFICE_HOUR = 'OFFICE_HOUR';
    case WEBINAR = 'WEBINAR';

    public function label(): string
    {
        return match ($this) {
            self::LIVE_CLASS => 'Kelas langsung',
            self::OFFICE_HOUR => 'Jam konsultasi',
            self::WEBINAR => 'Webinar',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::LIVE_CLASS => 'video',
            self::OFFICE_HOUR => 'clock',
            self::WEBINAR => 'monitor-play',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
