<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum CertificateVerificationResult — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum CertificateVerificationResult: string
{
    case VALID = 'VALID';
    case NOT_FOUND = 'NOT_FOUND';
    case REVOKED = 'REVOKED';
    case EXPIRED = 'EXPIRED';
    case NOT_YET_ISSUED = 'NOT_YET_ISSUED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::VALID => 'Valid',
            self::NOT_FOUND => 'Tidak Ditemukan',
            self::REVOKED => 'Dicabut',
            self::EXPIRED => 'Kedaluwarsa',
            self::NOT_YET_ISSUED => 'Belum Diterbitkan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
