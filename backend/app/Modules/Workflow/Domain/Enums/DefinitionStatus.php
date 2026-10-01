<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Status definisi workflow (§15).
 *
 * Hanya definisi berstatus ACTIVE yang boleh melahirkan instance baru. Definisi
 * TESTING boleh disimulasikan dan disubmit **hanya** oleh penguji
 * (`workflow.definition.test`) agar tidak ada request bisnis nyata yang
 * berjalan di atas rancangan yang belum diterbitkan.
 */
enum DefinitionStatus: string
{
    case DRAFT = 'DRAFT';
    case TESTING = 'TESTING';
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case ARCHIVED = 'ARCHIVED';

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

    /** Hanya ACTIVE yang boleh dipakai request bisnis baru (§15). */
    public function canStartRequest(): bool
    {
        return $this === self::ACTIVE;
    }

    /** Draf & uji coba masih boleh diubah; selebihnya tidak. */
    public function isEditable(): bool
    {
        return in_array($this, [self::DRAFT, self::TESTING], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
