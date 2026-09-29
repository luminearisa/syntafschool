<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Status rilis nilai akhir per siswa (§16–§18).
 */
enum GradeReleaseStatus: string
{
    case DRAFT = 'DRAFT';
    case RELEASED = 'RELEASED';
    case REVOKED = 'REVOKED';
    case LOCKED = 'LOCKED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Belum dirilis',
            self::RELEASED => 'Dirilis',
            self::REVOKED => 'Rilis dibatalkan',
            self::LOCKED => 'Terkunci',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::RELEASED => 'green',
            self::REVOKED => 'red',
            self::LOCKED => 'amber',
        };
    }

    public function isVisible(): bool
    {
        return in_array($this, [self::RELEASED, self::LOCKED], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
