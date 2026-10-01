<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum MembershipStatus — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum MembershipStatus: string
{
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case INACTIVE = 'INACTIVE';
    case COMPLETED = 'COMPLETED';
    case WITHDRAWN = 'WITHDRAWN';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Aktif',
            self::SUSPENDED => 'Ditangguhkan',
            self::INACTIVE => 'Tidak Aktif',
            self::COMPLETED => 'Selesai',
            self::WITHDRAWN => 'Mengundurkan Diri',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Keanggotaan yang masih dihitung aktif untuk kapasitas. */
    public function countsAsActive(): bool
    {
        return in_array($this, [self::ACTIVE, self::SUSPENDED], true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::COMPLETED, self::WITHDRAWN, self::INACTIVE], true);
    }
}
