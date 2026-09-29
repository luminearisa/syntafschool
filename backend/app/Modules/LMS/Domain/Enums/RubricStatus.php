<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Status rubric (§23).
 */
enum RubricStatus: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case ARCHIVED = 'ARCHIVED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::ACTIVE => 'Aktif',
            self::ARCHIVED => 'Diarsipkan',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::ACTIVE => 'green',
            self::ARCHIVED => 'amber',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
