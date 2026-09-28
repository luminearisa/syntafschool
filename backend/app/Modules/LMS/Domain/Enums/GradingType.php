<?php

namespace App\Modules\LMS\Domain\Enums;

/** Skala penilaian aktivitas (§4). Struktur rubrik penuh menyusul di phase 3. */
enum GradingType: string
{
    case POINTS = 'POINTS';
    case PASS_FAIL = 'PASS_FAIL';
    case RUBRIC = 'RUBRIC';

    public function label(): string
    {
        return match ($this) {
            self::POINTS => 'Poin (angka)',
            self::PASS_FAIL => 'Lulus / Tidak lulus',
            self::RUBRIC => 'Rubrik',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
