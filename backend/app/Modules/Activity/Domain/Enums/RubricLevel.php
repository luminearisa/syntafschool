<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum RubricLevel — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum RubricLevel: string
{
    case BEGINNER = 'BEGINNER';
    case DEVELOPING = 'DEVELOPING';
    case COMPETENT = 'COMPETENT';
    case ADVANCED = 'ADVANCED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::BEGINNER => 'Pemula',
            self::DEVELOPING => 'Berkembang',
            self::COMPETENT => 'Cakap',
            self::ADVANCED => 'Mahir',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
