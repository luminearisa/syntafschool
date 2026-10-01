<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum ResultAward — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum ResultAward: string
{
    case GOLD = 'GOLD';
    case SILVER = 'SILVER';
    case BRONZE = 'BRONZE';
    case WINNER = 'WINNER';
    case RUNNER_UP = 'RUNNER_UP';
    case FINALIST = 'FINALIST';
    case PARTICIPANT = 'PARTICIPANT';
    case BEST_SPEAKER = 'BEST_SPEAKER';
    case BEST_TEAM = 'BEST_TEAM';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::GOLD => 'Emas',
            self::SILVER => 'Perak',
            self::BRONZE => 'Perunggu',
            self::WINNER => 'Juara',
            self::RUNNER_UP => 'Runner Up',
            self::FINALIST => 'Finalis',
            self::PARTICIPANT => 'Peserta',
            self::BEST_SPEAKER => 'Pembicara Terbaik',
            self::BEST_TEAM => 'Tim Terbaik',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
