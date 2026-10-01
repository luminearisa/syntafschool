<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum VerificationStatus — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum VerificationStatus: string
{
    case PENDING = 'PENDING';
    case VERIFIED = 'VERIFIED';
    case REJECTED = 'REJECTED';
    case REVOKED = 'REVOKED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu Verifikasi',
            self::VERIFIED => 'Terverifikasi',
            self::REJECTED => 'Ditolak',
            self::REVOKED => 'Dicabut',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
