<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum SelectionDecision — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum SelectionDecision: string
{
    case PENDING = 'PENDING';
    case SELECTED = 'SELECTED';
    case WAITLISTED = 'WAITLISTED';
    case REJECTED = 'REJECTED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu',
            self::SELECTED => 'Terpilih',
            self::WAITLISTED => 'Daftar Tunggu',
            self::REJECTED => 'Tidak Lolos',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
