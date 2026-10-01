<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Status versi workflow (§14).
 *
 * Satu definisi dapat punya banyak versi; hanya **satu** yang ACTIVE pada satu
 * waktu. Instance lama tetap terikat pada versi yang dipakai saat dibuat —
 * menerbitkan v3 tidak mengubah jalan request yang sedang berjalan.
 */
enum VersionStatus: string
{
    case DRAFT = 'DRAFT';
    case TESTING = 'TESTING';
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case ARCHIVED = 'ARCHIVED';

    public function isEditable(): bool
    {
        return in_array($this, [self::DRAFT, self::TESTING], true);
    }

    public function canStartRequest(): bool
    {
        return $this === self::ACTIVE;
    }

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::TESTING => 'Uji Coba',
            self::ACTIVE => 'Aktif',
            self::INACTIVE => 'Nonaktif',
            self::ARCHIVED => 'Diarsipkan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
