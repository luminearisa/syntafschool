<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Dasar penentuan sebuah course dinyatakan tuntas (§40).
 */
enum CourseCompletionRule: string
{
    case ALL_REQUIRED_ACTIVITIES = 'ALL_REQUIRED_ACTIVITIES';
    case MINIMUM_PROGRESS = 'MINIMUM_PROGRESS';
    case MINIMUM_GRADE = 'MINIMUM_GRADE';
    case REQUIRED_FINAL_ASSESSMENT = 'REQUIRED_FINAL_ASSESSMENT';
    case MANUAL = 'MANUAL';

    public function label(): string
    {
        return match ($this) {
            self::ALL_REQUIRED_ACTIVITIES => 'Semua aktivitas wajib selesai',
            self::MINIMUM_PROGRESS => 'Progress minimum',
            self::MINIMUM_GRADE => 'Nilai minimum',
            self::REQUIRED_FINAL_ASSESSMENT => 'Asesmen akhir lulus',
            self::MANUAL => 'Ditentukan manual oleh guru',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
