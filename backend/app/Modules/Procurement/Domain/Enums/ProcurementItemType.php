<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Jenis item pengadaan (§8) — barang maupun jasa. */
enum ProcurementItemType: string
{
    use EnumOptions;

    case GOODS = 'GOODS';
    case SERVICE = 'SERVICE';
    case SUBSCRIPTION = 'SUBSCRIPTION';
    case RENTAL = 'RENTAL';
    case MAINTENANCE = 'MAINTENANCE';
    case CONSTRUCTION = 'CONSTRUCTION';
    case CONSULTING = 'CONSULTING';
    case RECURRING = 'RECURRING';

    public function label(): string
    {
        return match ($this) {
            self::GOODS => 'Barang',
            self::SERVICE => 'Jasa',
            self::SUBSCRIPTION => 'Langganan',
            self::RENTAL => 'Sewa',
            self::MAINTENANCE => 'Pemeliharaan',
            self::CONSTRUCTION => 'Konstruksi',
            self::CONSULTING => 'Konsultansi',
            self::RECURRING => 'Berulang',
        };
    }

    /** Butuh penerimaan jasa, bukan goods receipt. */
    public function isService(): bool
    {
        return in_array($this, [self::SERVICE, self::SUBSCRIPTION, self::CONSULTING, self::MAINTENANCE, self::RECURRING], true);
    }
}
