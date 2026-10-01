<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum ConsentScope — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum ConsentScope: string
{
    case PARTICIPATION = 'PARTICIPATION';
    case TRIP = 'TRIP';
    case COMPETITION = 'COMPETITION';
    case EQUIPMENT = 'EQUIPMENT';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PARTICIPATION => 'Keikutsertaan',
            self::TRIP => 'Perjalanan',
            self::COMPETITION => 'Kompetisi',
            self::EQUIPMENT => 'Peralatan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
