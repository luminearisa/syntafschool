<?php

namespace App\Modules\LMS\Domain\Enums;

enum CourseType: string
{
    case ACADEMIC = 'ACADEMIC';
    case NON_ACADEMIC = 'NON_ACADEMIC';
    case TRAINING = 'TRAINING';
    case WORKSHOP = 'WORKSHOP';
    case GENERAL = 'GENERAL';

    public function label(): string
    {
        return match ($this) {
            self::ACADEMIC => 'Akademik',
            self::NON_ACADEMIC => 'Non-Akademik',
            self::TRAINING => 'Pelatihan',
            self::WORKSHOP => 'Workshop',
            self::GENERAL => 'Umum',
        };
    }

    /** Course akademik tidak boleh didaftari sendiri oleh siswa (§16). */
    public function isAcademic(): bool
    {
        return $this === self::ACADEMIC;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
