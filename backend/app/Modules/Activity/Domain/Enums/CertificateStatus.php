<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum CertificateStatus — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum CertificateStatus: string
{
    case DRAFT = 'DRAFT';
    case ISSUED = 'ISSUED';
    case REVOKED = 'REVOKED';
    case EXPIRED = 'EXPIRED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::ISSUED => 'Terbit',
            self::REVOKED => 'Dicabut',
            self::EXPIRED => 'Kedaluwarsa',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
