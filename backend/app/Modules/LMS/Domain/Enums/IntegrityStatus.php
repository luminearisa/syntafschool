<?php

namespace App\Modules\LMS\Domain\Enums;

/** Ringkasan hasil pemeriksaan integritas (§24). */
enum IntegrityStatus: string
{
    case OK = 'OK';
    case WARNINGS = 'WARNINGS';
    case CRITICAL = 'CRITICAL';

    public function label(): string
    {
        return match ($this) {
            self::OK => 'Sehat',
            self::WARNINGS => 'Ada catatan',
            self::CRITICAL => 'Perlu tindakan',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::OK => 'green',
            self::WARNINGS => 'amber',
            self::CRITICAL => 'red',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
