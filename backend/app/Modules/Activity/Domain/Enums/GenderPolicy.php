<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum GenderPolicy — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum GenderPolicy: string
{
    case ANY = 'ANY';
    case MALE = 'MALE';
    case FEMALE = 'FEMALE';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::ANY => 'Semua',
            self::MALE => 'Putra',
            self::FEMALE => 'Putri',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
