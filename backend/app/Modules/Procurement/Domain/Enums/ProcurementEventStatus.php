<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Status peristiwa pengadaan gabungan (§11). */
enum ProcurementEventStatus: string
{
    use EnumOptions;

    case OPEN = 'OPEN';
    case RFQ_ISSUED = 'RFQ_ISSUED';
    case QUOTING = 'QUOTING';
    case EVALUATED = 'EVALUATED';
    case SELECTED = 'SELECTED';
    case PO_CREATED = 'PO_CREATED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Terbuka',
            self::RFQ_ISSUED => 'RFQ Terbit',
            self::QUOTING => 'Pengumpulan Penawaran',
            self::EVALUATED => 'Dievaluasi',
            self::SELECTED => 'Vendor Terpilih',
            self::PO_CREATED => 'PO Dibuat',
            self::COMPLETED => 'Selesai',
            self::CANCELLED => 'Dibatalkan',
        };
    }
}
