<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum ConsentStatus — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum ConsentStatus: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case DECLINED = 'DECLINED';
    case EXPIRED = 'EXPIRED';
    case REVOKED = 'REVOKED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu',
            self::APPROVED => 'Disetujui',
            self::DECLINED => 'Ditolak',
            self::EXPIRED => 'Kedaluwarsa',
            self::REVOKED => 'Dicabut',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
