<?php

namespace App\Modules\Supervisi\Domain\Enums;

/** Cara nilai akhir sebuah versi instrumen dihitung. */
enum ScoringMode: string
{
    case SUM = 'SUM';               // jumlah skor indikator
    case AVERAGE = 'AVERAGE';       // rata-rata skor indikator
    case WEIGHTED = 'WEIGHTED';     // Σ (skor × bobot) / Σ bobot
    case PERCENT = 'PERCENT';       // konversi ke skala 100 dari skor maksimum
    case CUSTOM = 'CUSTOM';         // rumus khusus (PKG, NKKS, koreksi kehadiran, kuadran)
    case NARRATIVE = 'NARRATIVE';   // instrumen dokumentasi: tidak dihitung menjadi nilai

    public function label(): string
    {
        return match ($this) {
            self::SUM => 'Jumlah Skor',
            self::AVERAGE => 'Rata-rata Skor',
            self::WEIGHTED => 'Berbobot',
            self::PERCENT => 'Persentase dari Skor Maksimum',
            self::CUSTOM => 'Rumus Khusus',
            self::NARRATIVE => 'Naratif (tanpa nilai)',
        };
    }

    /** Nilai akhir 0–100 untuk mode ini? */
    public function yieldsPercent(): bool
    {
        return in_array($this, [self::PERCENT, self::CUSTOM, self::WEIGHTED], true);
    }

    /** Mode naratif tidak menghasilkan nilai akhir. */
    public function isScored(): bool
    {
        return $this !== self::NARRATIVE;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
