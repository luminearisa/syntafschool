<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Jenis vendor/rekanan. */
enum VendorType: string
{
    use EnumOptions;

    case SUPPLIER = 'SUPPLIER';
    case SERVICE_PROVIDER = 'SERVICE_PROVIDER';
    case CONTRACTOR = 'CONTRACTOR';
    case CONSULTANT = 'CONSULTANT';
    case DISTRIBUTOR = 'DISTRIBUTOR';
    case MANUFACTURER = 'MANUFACTURER';
    case RENTAL_PROVIDER = 'RENTAL_PROVIDER';
    case TRANSPORTATION_PROVIDER = 'TRANSPORTATION_PROVIDER';
    case CATERING_PROVIDER = 'CATERING_PROVIDER';
    case MAINTENANCE_PROVIDER = 'MAINTENANCE_PROVIDER';
    case TECHNOLOGY_PROVIDER = 'TECHNOLOGY_PROVIDER';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::SUPPLIER => 'Pemasok Barang',
            self::SERVICE_PROVIDER => 'Penyedia Jasa',
            self::CONTRACTOR => 'Kontraktor',
            self::CONSULTANT => 'Konsultan',
            self::DISTRIBUTOR => 'Distributor',
            self::MANUFACTURER => 'Produsen',
            self::RENTAL_PROVIDER => 'Penyedia Sewa',
            self::TRANSPORTATION_PROVIDER => 'Penyedia Transportasi',
            self::CATERING_PROVIDER => 'Penyedia Katering',
            self::MAINTENANCE_PROVIDER => 'Penyedia Pemeliharaan',
            self::TECHNOLOGY_PROVIDER => 'Penyedia Teknologi',
            self::OTHER => 'Lainnya',
        };
    }
}
