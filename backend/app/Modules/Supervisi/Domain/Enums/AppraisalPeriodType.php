<?php

namespace App\Modules\Supervisi\Domain\Enums;

/** Periode penilaian kinerja. */
enum AppraisalPeriodType: string
{
    case BULANAN = 'BULANAN';
    case TRIWULAN = 'TRIWULAN';
    case SEMESTER = 'SEMESTER';
    case TAHUNAN = 'TAHUNAN';

    public function label(): string
    {
        return match ($this) {
            self::BULANAN => 'Bulanan',
            self::TRIWULAN => 'Triwulanan',
            self::SEMESTER => 'Semester',
            self::TAHUNAN => 'Tahunan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
