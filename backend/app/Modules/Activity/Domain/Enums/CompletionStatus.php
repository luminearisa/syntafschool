<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum CompletionStatus — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum CompletionStatus: string
{
    case PENDING = 'PENDING';
    case ELIGIBLE = 'ELIGIBLE';
    case COMPLETED = 'COMPLETED';
    case WAIVED = 'WAIVED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu',
            self::ELIGIBLE => 'Memenuhi Syarat',
            self::COMPLETED => 'Selesai',
            self::WAIVED => 'Dikecualikan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
