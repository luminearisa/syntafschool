<?php

namespace App\Modules\Supervisi\Domain\Enums;

/** Status versi instrumen. */
enum InstrumentVersionStatus: string
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

    /** Versi terbit bersifat beku; perubahan harus lewat kloning versi baru. */
    public function isLocked(): bool
    {
        return $this !== self::DRAFT;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
