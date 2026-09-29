<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Status kompetensi siswa (§30). Kompetensi **tidak** diturunkan otomatis dari
 * nilai angka — guru menetapkannya berdasarkan evidence.
 */
enum CompetencyStatus: string
{
    case NOT_STARTED = 'NOT_STARTED';
    case DEVELOPING = 'DEVELOPING';
    case COMPETENT = 'COMPETENT';
    case MASTERED = 'MASTERED';

    public function label(): string
    {
        return match ($this) {
            self::NOT_STARTED => 'Belum dimulai',
            self::DEVELOPING => 'Berkembang',
            self::COMPETENT => 'Kompeten',
            self::MASTERED => 'Mahir',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::NOT_STARTED => 'gray',
            self::DEVELOPING => 'amber',
            self::COMPETENT => 'green',
            self::MASTERED => 'brand',
        };
    }

    public function isAchieved(): bool
    {
        return in_array($this, [self::COMPETENT, self::MASTERED], true);
    }

    /** Urutan maju untuk usulan dari evidence (tidak pernah turun otomatis). */
    public function rank(): int
    {
        return match ($this) {
            self::NOT_STARTED => 0,
            self::DEVELOPING => 1,
            self::COMPETENT => 2,
            self::MASTERED => 3,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
