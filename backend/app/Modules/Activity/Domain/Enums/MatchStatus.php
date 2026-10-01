<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum MatchStatus — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum MatchStatus: string
{
    case SCHEDULED = 'SCHEDULED';
    case ONGOING = 'ONGOING';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
    case WALKOVER = 'WALKOVER';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::SCHEDULED => 'Terjadwal',
            self::ONGOING => 'Berlangsung',
            self::COMPLETED => 'Selesai',
            self::CANCELLED => 'Dibatalkan',
            self::WALKOVER => 'Walkover',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
