<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum AchievementRank — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum AchievementRank: string
{
    case FIRST = 'FIRST';
    case SECOND = 'SECOND';
    case THIRD = 'THIRD';
    case FOURTH = 'FOURTH';
    case FINALIST = 'FINALIST';
    case WINNER = 'WINNER';
    case RUNNER_UP = 'RUNNER_UP';
    case BEST_SPEAKER = 'BEST_SPEAKER';
    case PARTICIPANT = 'PARTICIPANT';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::FIRST => 'Juara 1',
            self::SECOND => 'Juara 2',
            self::THIRD => 'Juara 3',
            self::FOURTH => 'Juara Harapan',
            self::FINALIST => 'Finalis',
            self::WINNER => 'Pemenang',
            self::RUNNER_UP => 'Runner Up',
            self::BEST_SPEAKER => 'Pembicara Terbaik',
            self::PARTICIPANT => 'Peserta',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
