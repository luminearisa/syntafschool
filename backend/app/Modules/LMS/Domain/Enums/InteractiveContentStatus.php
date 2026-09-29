<?php

namespace App\Modules\LMS\Domain\Enums;

enum InteractiveContentStatus: string
{
    case DRAFT = 'DRAFT';
    case PUBLISHED = 'PUBLISHED';
    case ARCHIVED = 'ARCHIVED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::PUBLISHED => 'Terbit',
            self::ARCHIVED => 'Diarsipkan',
        };
    }

    public function isPublished(): bool
    {
        return $this === self::PUBLISHED;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
