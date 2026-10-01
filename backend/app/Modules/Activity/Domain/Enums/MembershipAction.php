<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum MembershipAction — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum MembershipAction: string
{
    case CREATED = 'CREATED';
    case SUSPENDED = 'SUSPENDED';
    case REACTIVATED = 'REACTIVATED';
    case ROLE_CHANGED = 'ROLE_CHANGED';
    case WITHDRAWN = 'WITHDRAWN';
    case TRANSFERRED = 'TRANSFERRED';
    case COMPLETED = 'COMPLETED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::CREATED => 'Dibuat',
            self::SUSPENDED => 'Ditangguhkan',
            self::REACTIVATED => 'Diaktifkan Kembali',
            self::ROLE_CHANGED => 'Peran Diubah',
            self::WITHDRAWN => 'Mengundurkan Diri',
            self::TRANSFERRED => 'Dipindahkan',
            self::COMPLETED => 'Diselesaikan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
