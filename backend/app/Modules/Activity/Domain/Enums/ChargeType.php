<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum ChargeType — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum ChargeType: string
{
    case REGISTRATION = 'REGISTRATION';
    case MONTHLY = 'MONTHLY';
    case EQUIPMENT = 'EQUIPMENT';
    case COMPETITION = 'COMPETITION';
    case TRIP = 'TRIP';
    case UNIFORM = 'UNIFORM';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::REGISTRATION => 'Pendaftaran',
            self::MONTHLY => 'Iuran Bulanan',
            self::EQUIPMENT => 'Peralatan',
            self::COMPETITION => 'Kompetisi',
            self::TRIP => 'Perjalanan',
            self::UNIFORM => 'Seragam',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
