<?php

namespace App\Modules\LMS\Domain\Enums;

enum LiveAttendanceStatus: string
{
    case PRESENT = 'PRESENT';
    case LATE = 'LATE';
    case ABSENT = 'ABSENT';
    case EXCUSED = 'EXCUSED';

    public function label(): string
    {
        return match ($this) {
            self::PRESENT => 'Hadir',
            self::LATE => 'Terlambat',
            self::ABSENT => 'Tidak hadir',
            self::EXCUSED => 'Izin',
        };
    }

    /** Dianggap hadir untuk rekap. */
    public function countsAsPresent(): bool
    {
        return in_array($this, [self::PRESENT, self::LATE], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
