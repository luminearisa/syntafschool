<?php

namespace App\Modules\LMS\Domain\Enums;

enum LearningPathStatus: string
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

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
