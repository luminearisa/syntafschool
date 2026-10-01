<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum RoundType — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum RoundType: string
{
    case QUALIFICATION = 'QUALIFICATION';
    case GROUP = 'GROUP';
    case QUARTER_FINAL = 'QUARTER_FINAL';
    case SEMI_FINAL = 'SEMI_FINAL';
    case FINAL = 'FINAL';
    case FRIENDLY = 'FRIENDLY';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::QUALIFICATION => 'Kualifikasi',
            self::GROUP => 'Penyisihan Grup',
            self::QUARTER_FINAL => 'Perempat Final',
            self::SEMI_FINAL => 'Semifinal',
            self::FINAL => 'Final',
            self::FRIENDLY => 'Uji Coba',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
