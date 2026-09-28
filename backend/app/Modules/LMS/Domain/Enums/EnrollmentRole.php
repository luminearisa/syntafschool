<?php

namespace App\Modules\LMS\Domain\Enums;

enum EnrollmentRole: string
{
    case TEACHER = 'TEACHER';
    case STUDENT = 'STUDENT';
    case ASSISTANT = 'ASSISTANT';

    public function label(): string
    {
        return match ($this) {
            self::TEACHER => 'Guru',
            self::STUDENT => 'Siswa',
            self::ASSISTANT => 'Asisten',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
