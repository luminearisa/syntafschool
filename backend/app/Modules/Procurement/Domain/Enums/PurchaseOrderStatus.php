<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Status Purchase Order (§19). */
enum PurchaseOrderStatus: string
{
    use EnumOptions;

    case DRAFT = 'DRAFT';
    case PENDING_APPROVAL = 'PENDING_APPROVAL';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case RETURNED = 'RETURNED';
    case ISSUED = 'ISSUED';
    case VENDOR_CONFIRMED = 'VENDOR_CONFIRMED';
    case PARTIALLY_RECEIVED = 'PARTIALLY_RECEIVED';
    case FULLY_RECEIVED = 'FULLY_RECEIVED';
    case CLOSED = 'CLOSED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::PENDING_APPROVAL => 'Menunggu Persetujuan',
            self::APPROVED => 'Disetujui',
            self::REJECTED => 'Ditolak',
            self::RETURNED => 'Dikembalikan',
            self::ISSUED => 'Diterbitkan',
            self::VENDOR_CONFIRMED => 'Dikonfirmasi Vendor',
            self::PARTIALLY_RECEIVED => 'Diterima Sebagian',
            self::FULLY_RECEIVED => 'Diterima Penuh',
            self::CLOSED => 'Ditutup',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    /** PO yang sudah diterbitkan tidak boleh disunting langsung (§52.3). */
    public function isIssued(): bool
    {
        return in_array($this, [
            self::ISSUED, self::VENDOR_CONFIRMED, self::PARTIALLY_RECEIVED,
            self::FULLY_RECEIVED, self::CLOSED,
        ], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::CLOSED, self::CANCELLED, self::REJECTED], true);
    }
}
