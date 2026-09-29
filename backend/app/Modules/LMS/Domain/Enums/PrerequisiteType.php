<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Jenis dependency antar materi/aktivitas (§3).
 *
 * Dievaluasi oleh mesin completion Phase 1 (`CourseCompletionService`),
 * sehingga tidak ada mesin prasyarat kedua.
 */
enum PrerequisiteType: string
{
    case COMPLETION = 'COMPLETION';
    case GRADE = 'GRADE';
    case PASSING_GRADE = 'PASSING_GRADE';
    case ACTIVITY_RESULT = 'ACTIVITY_RESULT';
    case DATE = 'DATE';

    public function label(): string
    {
        return match ($this) {
            self::COMPLETION => 'Menyelesaikan materi',
            self::GRADE => 'Nilai minimal',
            self::PASSING_GRADE => 'Lulus (nilai kelulusan)',
            self::ACTIVITY_RESULT => 'Hasil aktivitas',
            self::DATE => 'Tanggal rilis',
        };
    }

    /** Butuh rujukan item/aktivitas lain. */
    public function requiresReference(): bool
    {
        return $this !== self::DATE;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
