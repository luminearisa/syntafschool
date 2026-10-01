<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum FacilityBookingStatus — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum FacilityBookingStatus: string
{
    case REQUESTED = 'REQUESTED';
    case CONFIRMED = 'CONFIRMED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';
    case CONFLICT = 'CONFLICT';
    case FAILED = 'FAILED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::REQUESTED => 'Diminta',
            self::CONFIRMED => 'Terkonfirmasi',
            self::REJECTED => 'Ditolak',
            self::CANCELLED => 'Dibatalkan',
            self::CONFLICT => 'Bentrok',
            self::FAILED => 'Gagal (modul sarpras bermasalah)',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
