<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Kehadiran pada sesi program kelompok.
 *
 * `SICK` ada di sini meski Modul Attendance tidak memisahkannya: konteks BK sering
 * membutuhkannya untuk menilai apakah ketidakhadiran perlu ditindaklanjuti.
 */
enum GroupAttendanceStatus: string
{
    case PRESENT = 'PRESENT';
    case ABSENT = 'ABSENT';
    case LATE = 'LATE';
    case EXCUSED = 'EXCUSED';
    case SICK = 'SICK';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PRESENT => 'Hadir',
            self::ABSENT => 'Tidak hadir',
            self::LATE => 'Terlambat',
            self::EXCUSED => 'Izin',
            self::SICK => 'Sakit',
        };
    }

    public function countsAsPresent(): bool
    {
        return in_array($this, [self::PRESENT, self::LATE], true);
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
