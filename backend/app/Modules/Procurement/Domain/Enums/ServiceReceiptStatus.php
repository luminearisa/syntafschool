<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Status penerimaan jasa (§27). */
enum ServiceReceiptStatus: string
{
    use EnumOptions;

    case DRAFT = 'DRAFT';
    case IN_PROGRESS = 'IN_PROGRESS';
    case SUBMITTED = 'SUBMITTED';
    case ACCEPTED = 'ACCEPTED';
    case PARTIALLY_ACCEPTED = 'PARTIALLY_ACCEPTED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::IN_PROGRESS => 'Berjalan',
            self::SUBMITTED => 'Diajukan',
            self::ACCEPTED => 'Diterima',
            self::PARTIALLY_ACCEPTED => 'Diterima Sebagian',
            self::REJECTED => 'Ditolak',
            self::CANCELLED => 'Dibatalkan',
        };
    }
}
