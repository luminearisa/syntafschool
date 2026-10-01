<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum ParticipantStatus — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum ParticipantStatus: string
{
    case REGISTERED = 'REGISTERED';
    case VERIFIED = 'VERIFIED';
    case REJECTED = 'REJECTED';
    case WITHDRAWN = 'WITHDRAWN';
    case FINALIZED = 'FINALIZED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::REGISTERED => 'Terdaftar',
            self::VERIFIED => 'Terverifikasi',
            self::REJECTED => 'Ditolak',
            self::WITHDRAWN => 'Mengundurkan Diri',
            self::FINALIZED => 'Final',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
