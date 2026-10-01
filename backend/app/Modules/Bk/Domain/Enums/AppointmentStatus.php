<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Status janji temu BK.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum AppointmentStatus: string
{
    case SCHEDULED = 'SCHEDULED';
    case CONFIRMED = 'CONFIRMED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
    case NO_SHOW = 'NO_SHOW';
    case RESCHEDULED = 'RESCHEDULED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::SCHEDULED => 'Terjadwal',
            self::CONFIRMED => 'Dikonfirmasi',
            self::COMPLETED => 'Selesai',
            self::CANCELLED => 'Dibatalkan',
            self::NO_SHOW => 'Tidak hadir',
            self::RESCHEDULED => 'Dijadwalkan ulang',
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
