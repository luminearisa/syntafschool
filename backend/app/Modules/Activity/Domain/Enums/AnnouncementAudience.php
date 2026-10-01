<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum AnnouncementAudience — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum AnnouncementAudience: string
{
    case MEMBERS = 'MEMBERS';
    case COACHES = 'COACHES';
    case PARENTS = 'PARENTS';
    case ALL = 'ALL';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::MEMBERS => 'Anggota',
            self::COACHES => 'Pembina',
            self::PARENTS => 'Orang Tua',
            self::ALL => 'Semua',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
