<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Status penerimaan barang (§22, §23). */
enum ReceiptStatus: string
{
    use EnumOptions;

    case DRAFT = 'DRAFT';
    case RECEIVED = 'RECEIVED';
    case INSPECTED = 'INSPECTED';
    case ACCEPTED = 'ACCEPTED';
    case PARTIALLY_ACCEPTED = 'PARTIALLY_ACCEPTED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::RECEIVED => 'Diterima',
            self::INSPECTED => 'Diinspeksi',
            self::ACCEPTED => 'Diterima Baik',
            self::PARTIALLY_ACCEPTED => 'Diterima Sebagian',
            self::REJECTED => 'Ditolak',
            self::CANCELLED => 'Dibatalkan',
        };
    }
}
