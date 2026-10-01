<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum SelectionMethod — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum SelectionMethod: string
{
    case OPEN = 'OPEN';
    case APPROVAL = 'APPROVAL';
    case AUDITION = 'AUDITION';
    case TRIAL = 'TRIAL';
    case COMPETITIVE = 'COMPETITIVE';
    case INVITATION = 'INVITATION';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Terbuka',
            self::APPROVAL => 'Persetujuan',
            self::AUDITION => 'Audisi',
            self::TRIAL => 'Tes Coba',
            self::COMPETITIVE => 'Kompetitif',
            self::INVITATION => 'Undangan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
