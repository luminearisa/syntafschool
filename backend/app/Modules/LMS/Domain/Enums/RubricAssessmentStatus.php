<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Status penilaian rubric (§24, §58).
 */
enum RubricAssessmentStatus: string
{
    case DRAFT = 'DRAFT';
    case COMPLETED = 'COMPLETED';
    case RELEASED = 'RELEASED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::COMPLETED => 'Selesai',
            self::RELEASED => 'Dirilis',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::COMPLETED => 'blue',
            self::RELEASED => 'green',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
