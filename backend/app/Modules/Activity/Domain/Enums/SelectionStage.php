<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum SelectionStage — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum SelectionStage: string
{
    case SCREENING = 'SCREENING';
    case TRIAL = 'TRIAL';
    case AUDITION = 'AUDITION';
    case INTERVIEW = 'INTERVIEW';
    case ASSESSMENT = 'ASSESSMENT';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::SCREENING => 'Penyaringan Berkas',
            self::TRIAL => 'Tes Coba',
            self::AUDITION => 'Audisi',
            self::INTERVIEW => 'Wawancara',
            self::ASSESSMENT => 'Penilaian',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
