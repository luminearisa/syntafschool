<?php

namespace App\Modules\Supervisi\Domain\Enums;

/** Status dokumen penilaian kinerja. */
enum AppraisalStatus: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case REVIEWED = 'REVIEWED';
    case PUBLISHED = 'PUBLISHED';
    case DISPUTED = 'DISPUTED';
    case ARCHIVED = 'ARCHIVED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::SUBMITTED => 'Diajukan',
            self::REVIEWED => 'Ditinjau Moderator',
            self::PUBLISHED => 'Diterbitkan',
            self::DISPUTED => 'Keberatan',
            self::ARCHIVED => 'Diarsipkan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
