<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/**
 * Status vendor sepanjang siklus onboarding (§5).
 *
 * Hanya vendor berstatus ACTIVE yang boleh dipakai pada PO, kecuali ada
 * override beralasan dengan izin khusus.
 */
enum VendorStatus: string
{
    use EnumOptions;

    case DRAFT = 'DRAFT';
    case REGISTERED = 'REGISTERED';
    case UNDER_VERIFICATION = 'UNDER_VERIFICATION';
    case VERIFIED = 'VERIFIED';
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case INACTIVE = 'INACTIVE';
    case REJECTED = 'REJECTED';
    case BLACKLISTED = 'BLACKLISTED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::REGISTERED => 'Terdaftar',
            self::UNDER_VERIFICATION => 'Dalam Verifikasi',
            self::VERIFIED => 'Terverifikasi',
            self::ACTIVE => 'Aktif',
            self::SUSPENDED => 'Ditangguhkan',
            self::INACTIVE => 'Nonaktif',
            self::REJECTED => 'Ditolak',
            self::BLACKLISTED => 'Daftar Hitam',
        };
    }

    /** Dapat dipakai untuk membuat PO. */
    public function isUsable(): bool
    {
        return $this === self::ACTIVE;
    }
}
