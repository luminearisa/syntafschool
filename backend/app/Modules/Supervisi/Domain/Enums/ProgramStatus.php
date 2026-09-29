<?php

namespace App\Modules\Supervisi\Domain\Enums;

/** Status program supervisi (tahunan/semester). */
enum ProgramStatus: string
{
    case DRAFT = 'DRAFT';
    case PUBLISHED = 'PUBLISHED';
    case CLOSED = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::PUBLISHED => 'Berjalan',
            self::CLOSED => 'Ditutup',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
