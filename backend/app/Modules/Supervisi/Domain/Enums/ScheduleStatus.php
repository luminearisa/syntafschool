<?php

namespace App\Modules\Supervisi\Domain\Enums;

/** Status jadwal (agenda) supervisi. */
enum ScheduleStatus: string
{
    case PLANNED = 'PLANNED';
    case INVITED = 'INVITED';
    case CONFIRMED = 'CONFIRMED';
    case RESCHEDULED = 'RESCHEDULED';
    case CANCELLED = 'CANCELLED';
    case DONE = 'DONE';

    public function label(): string
    {
        return match ($this) {
            self::PLANNED => 'Direncanakan',
            self::INVITED => 'Undangan Terkirim',
            self::CONFIRMED => 'Disepakati',
            self::RESCHEDULED => 'Diubah Jadwal',
            self::CANCELLED => 'Dibatalkan',
            self::DONE => 'Terlaksana',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::PLANNED, self::INVITED, self::CONFIRMED, self::RESCHEDULED], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
