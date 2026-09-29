<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Jenis bukti capaian kompetensi (§31).
 */
enum CompetencyEvidenceType: string
{
    case ACTIVITY = 'ACTIVITY';
    case ASSESSMENT = 'ASSESSMENT';
    case RUBRIC = 'RUBRIC';
    case MANUAL = 'MANUAL';
    case EXTERNAL = 'EXTERNAL';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVITY => 'Aktivitas',
            self::ASSESSMENT => 'Hasil penilaian',
            self::RUBRIC => 'Rubric',
            self::MANUAL => 'Catatan guru',
            self::EXTERNAL => 'Eksternal',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::ACTIVITY => 'brand',
            self::ASSESSMENT => 'blue',
            self::RUBRIC => 'green',
            self::MANUAL => 'gray',
            self::EXTERNAL => 'amber',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
