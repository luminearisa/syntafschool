<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Konfirmasi vendor atas PO (§21). */
enum VendorConfirmationStatus: string
{
    use EnumOptions;

    case PENDING = 'PENDING';
    case ACCEPTED = 'ACCEPTED';
    case PARTIALLY_ACCEPTED = 'PARTIALLY_ACCEPTED';
    case REJECTED = 'REJECTED';
    case REVISED = 'REVISED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu',
            self::ACCEPTED => 'Diterima',
            self::PARTIALLY_ACCEPTED => 'Diterima Sebagian',
            self::REJECTED => 'Ditolak',
            self::REVISED => 'Direvisi',
        };
    }
}
