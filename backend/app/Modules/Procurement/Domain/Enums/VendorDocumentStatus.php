<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Status verifikasi dokumen vendor (§5). */
enum VendorDocumentStatus: string
{
    use EnumOptions;

    case PENDING = 'PENDING';
    case SUBMITTED = 'SUBMITTED';
    case VERIFIED = 'VERIFIED';
    case REJECTED = 'REJECTED';
    case EXPIRED = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu',
            self::SUBMITTED => 'Diajukan',
            self::VERIFIED => 'Terverifikasi',
            self::REJECTED => 'Ditolak',
            self::EXPIRED => 'Kedaluwarsa',
        };
    }
}
