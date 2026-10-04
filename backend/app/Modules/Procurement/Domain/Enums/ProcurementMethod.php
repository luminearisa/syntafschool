<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/**
 * Metode pengadaan (§39). Jalur sah ditentukan oleh nilai + aturan di
 * `config/procurement.php` (jumlah penawaran minimum per metode).
 */
enum ProcurementMethod: string
{
    use EnumOptions;

    case DIRECT = 'DIRECT';
    case RFQ = 'RFQ';
    case COMPETITIVE = 'COMPETITIVE';
    case CONTRACT = 'CONTRACT';
    case EMERGENCY = 'EMERGENCY';

    public function label(): string
    {
        return match ($this) {
            self::DIRECT => 'Pembelian Langsung',
            self::RFQ => 'Permintaan Penawaran (RFQ)',
            self::COMPETITIVE => 'Pengadaan Kompetitif',
            self::CONTRACT => 'Pembelian via Kontrak',
            self::EMERGENCY => 'Pengadaan Darurat',
        };
    }

    /** Kunci konfigurasi di config('procurement.methods'). */
    public function configKey(): string
    {
        return match ($this) {
            self::DIRECT => 'direct',
            self::RFQ => 'rfq',
            self::COMPETITIVE => 'competitive',
            self::CONTRACT => 'rfq',
            self::EMERGENCY => 'direct',
        };
    }
}
