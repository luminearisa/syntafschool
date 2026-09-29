<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Cakupan skala nilai (§6): sekolah, course, atau tahun ajaran.
 */
enum GradeScaleScope: string
{
    case SCHOOL = 'SCHOOL';
    case COURSE = 'COURSE';
    case ACADEMIC_YEAR = 'ACADEMIC_YEAR';

    public function label(): string
    {
        return match ($this) {
            self::SCHOOL => 'Sekolah',
            self::COURSE => 'Course',
            self::ACADEMIC_YEAR => 'Tahun ajaran',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
