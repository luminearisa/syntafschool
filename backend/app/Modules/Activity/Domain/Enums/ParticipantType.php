<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum ParticipantType — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum ParticipantType: string
{
    case INDIVIDUAL = 'INDIVIDUAL';
    case TEAM = 'TEAM';
    case MIXED = 'MIXED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::INDIVIDUAL => 'Individu',
            self::TEAM => 'Tim',
            self::MIXED => 'Individu & Tim',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
