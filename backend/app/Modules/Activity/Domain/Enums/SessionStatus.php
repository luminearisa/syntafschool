<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum SessionStatus — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum SessionStatus: string
{
    case SCHEDULED = 'SCHEDULED';
    case OPEN = 'OPEN';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
    case POSTPONED = 'POSTPONED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::SCHEDULED => 'Terjadwal',
            self::OPEN => 'Dibuka',
            self::COMPLETED => 'Selesai',
            self::CANCELLED => 'Dibatalkan',
            self::POSTPONED => 'Ditunda',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
