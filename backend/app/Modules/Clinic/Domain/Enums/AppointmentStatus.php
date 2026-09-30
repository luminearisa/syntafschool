<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum AppointmentStatus — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum AppointmentStatus: string
{
    case SCHEDULED = 'SCHEDULED';
    case CHECKED_IN = 'CHECKED_IN';
    case IN_PROGRESS = 'IN_PROGRESS';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
    case NO_SHOW = 'NO_SHOW';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::SCHEDULED => 'Terjadwal',
            self::CHECKED_IN => 'Sudah Daftar',
            self::IN_PROGRESS => 'Sedang Dilayani',
            self::COMPLETED => 'Selesai',
            self::CANCELLED => 'Dibatalkan',
            self::NO_SHOW => 'Tidak Hadir',
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
