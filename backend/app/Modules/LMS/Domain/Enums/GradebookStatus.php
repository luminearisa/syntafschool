<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Status gradebook (§2, §44). Hanya gradebook ACTIVE yang boleh menerima
 * perubahan nilai; FROZEN/LOCKED memerlukan izin khusus (`lms.grade.lock`).
 */
enum GradebookStatus: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case LOCKED = 'LOCKED';
    case FROZEN = 'FROZEN';
    case ARCHIVED = 'ARCHIVED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::ACTIVE => 'Aktif',
            self::LOCKED => 'Terkunci',
            self::FROZEN => 'Dibekukan',
            self::ARCHIVED => 'Diarsipkan',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::ACTIVE => 'green',
            self::LOCKED => 'amber',
            self::FROZEN => 'blue',
            self::ARCHIVED => 'gray',
        };
    }

    /** Boleh menghitung/menyunting nilai? */
    public function isEditable(): bool
    {
        return in_array($this, [self::DRAFT, self::ACTIVE], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
