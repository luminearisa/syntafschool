<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Kebijakan nilai kosong (§9). Default PENDING: nilai yang belum ada **tidak**
 * dianggap nol, karena itu sumber kesalahan nilai siswa yang paling umum.
 */
enum MissingGradePolicy: string
{
    case MISSING_AS_ZERO = 'MISSING_AS_ZERO';
    case EXCLUDE = 'EXCLUDE';
    case PENDING = 'PENDING';

    public function label(): string
    {
        return match ($this) {
            self::MISSING_AS_ZERO => 'Hitung sebagai 0',
            self::EXCLUDE => 'Keluarkan dari perhitungan',
            self::PENDING => 'Tandai belum ada (tidak dihitung)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::MISSING_AS_ZERO => 'Nilai kosong dianggap 0 — hanya pakai bila kebijakan sekolah memang demikian.',
            self::EXCLUDE => 'Item tanpa nilai tidak masuk perhitungan sama sekali.',
            self::PENDING => 'Item tetap tampil sebagai "belum ada" dan tidak memengaruhi nilai akhir.',
        };
    }

    /** Apakah nilai kosong ikut dihitung? */
    public function affectsAverage(): bool
    {
        return $this === self::MISSING_AS_ZERO;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
