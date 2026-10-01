<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum IntegrationStatus — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum IntegrationStatus: string
{
    case OK = 'OK';
    case SKIPPED = 'SKIPPED';
    case FAILED = 'FAILED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::OK => 'Berhasil',
            self::SKIPPED => 'Dilewati',
            self::FAILED => 'Gagal',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
