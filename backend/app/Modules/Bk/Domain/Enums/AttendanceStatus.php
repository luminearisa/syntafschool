<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Kehadiran peserta pada janji temu/sesi.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum AttendanceStatus: string
{
    case PRESENT = 'PRESENT';
    case LATE = 'LATE';
    case ABSENT = 'ABSENT';
    case EXCUSED = 'EXCUSED';
    case UNKNOWN = 'UNKNOWN';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PRESENT => 'Hadir',
            self::LATE => 'Terlambat',
            self::ABSENT => 'Tidak hadir',
            self::EXCUSED => 'Izin',
            self::UNKNOWN => 'Belum diketahui',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
