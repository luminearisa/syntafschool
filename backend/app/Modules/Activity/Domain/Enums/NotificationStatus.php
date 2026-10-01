<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum NotificationStatus — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum NotificationStatus: string
{
    case SENT = 'SENT';
    case FAILED = 'FAILED';
    case SUPPRESSED = 'SUPPRESSED';
    case SKIPPED = 'SKIPPED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::SENT => 'Terkirim',
            self::FAILED => 'Gagal',
            self::SUPPRESSED => 'Ditahan',
            self::SKIPPED => 'Dilewati',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
