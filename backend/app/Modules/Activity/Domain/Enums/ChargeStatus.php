<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum ChargeStatus — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum ChargeStatus: string
{
    case REQUESTED = 'REQUESTED';
    case INVOICED = 'INVOICED';
    case PAID = 'PAID';
    case WAIVED = 'WAIVED';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::REQUESTED => 'Diminta',
            self::INVOICED => 'Ditagihkan',
            self::PAID => 'Dibayar',
            self::WAIVED => 'Dibebaskan',
            self::FAILED => 'Gagal',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
