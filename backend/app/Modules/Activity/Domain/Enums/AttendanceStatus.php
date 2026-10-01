<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum AttendanceStatus — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum AttendanceStatus: string
{
    case PRESENT = 'PRESENT';
    case ABSENT = 'ABSENT';
    case EXCUSED = 'EXCUSED';
    case LATE = 'LATE';
    case SICK = 'SICK';
    case PERMISSION = 'PERMISSION';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PRESENT => 'Hadir',
            self::ABSENT => 'Alpa',
            self::EXCUSED => 'Izin',
            self::LATE => 'Terlambat',
            self::SICK => 'Sakit',
            self::PERMISSION => 'Izin Resmi',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Hadir (termasuk terlambat) — dipakai menghitung persentase kehadiran. */
    public function countsAsPresent(): bool
    {
        return in_array($this, [self::PRESENT, self::LATE], true);
    }

    /** Absen yang diberitahukan (izin/sakit) — tidak dihitung alpa. */
    public function isExcused(): bool
    {
        return in_array($this, [self::EXCUSED, self::SICK, self::PERMISSION], true);
    }
}
