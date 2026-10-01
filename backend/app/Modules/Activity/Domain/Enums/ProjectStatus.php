<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum ProjectStatus — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum ProjectStatus: string
{
    case PLANNED = 'PLANNED';
    case ONGOING = 'ONGOING';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PLANNED => 'Direncanakan',
            self::ONGOING => 'Berjalan',
            self::COMPLETED => 'Selesai',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
