<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum WaitlistStatus — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum WaitlistStatus: string
{
    case WAITING = 'WAITING';
    case PROMOTED = 'PROMOTED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::WAITING => 'Menunggu',
            self::PROMOTED => 'Dipromosikan',
            self::CANCELLED => 'Dibatalkan',
            self::EXPIRED => 'Kedaluwarsa',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
