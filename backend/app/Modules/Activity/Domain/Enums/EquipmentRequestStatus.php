<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum EquipmentRequestStatus — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum EquipmentRequestStatus: string
{
    case REQUESTED = 'REQUESTED';
    case ISSUED = 'ISSUED';
    case PARTIAL = 'PARTIAL';
    case RETURNED = 'RETURNED';
    case REJECTED = 'REJECTED';
    case LOST = 'LOST';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::REQUESTED => 'Diminta',
            self::ISSUED => 'Diserahkan',
            self::PARTIAL => 'Sebagian',
            self::RETURNED => 'Dikembalikan',
            self::REJECTED => 'Ditolak',
            self::LOST => 'Hilang',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
