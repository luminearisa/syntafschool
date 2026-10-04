<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Status Purchase Request (§3, §8). */
enum PurchaseRequestStatus: string
{
    use EnumOptions;

    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case RETURNED = 'RETURNED';
    case CONVERTED = 'CONVERTED';
    case CLOSED = 'CLOSED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::SUBMITTED => 'Diajukan',
            self::UNDER_REVIEW => 'Sedang Ditinjau',
            self::APPROVED => 'Disetujui',
            self::REJECTED => 'Ditolak',
            self::RETURNED => 'Dikembalikan',
            self::CONVERTED => 'Dijadikan Pengadaan',
            self::CLOSED => 'Selesai',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    /** Masih dapat disunting pemohon. */
    public function isEditable(): bool
    {
        return in_array($this, [self::DRAFT, self::RETURNED], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::CLOSED, self::CANCELLED], true);
    }
}
