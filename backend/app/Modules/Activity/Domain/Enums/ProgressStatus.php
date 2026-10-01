<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum ProgressStatus — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum ProgressStatus: string
{
    case NOT_STARTED = 'NOT_STARTED';
    case ACTIVE = 'ACTIVE';
    case PROGRESSING = 'PROGRESSING';
    case COMPLETED = 'COMPLETED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::NOT_STARTED => 'Belum Mulai',
            self::ACTIVE => 'Aktif',
            self::PROGRESSING => 'Berkembang',
            self::COMPLETED => 'Selesai',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
