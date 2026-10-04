<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Jenis pengecualian invoice (§30). */
enum ExceptionType: string
{
    use EnumOptions;

    case QUANTITY_MISMATCH = 'QUANTITY_MISMATCH';
    case PRICE_MISMATCH = 'PRICE_MISMATCH';
    case TAX_MISMATCH = 'TAX_MISMATCH';
    case MISSING_RECEIVING = 'MISSING_RECEIVING';
    case DUPLICATE_INVOICE = 'DUPLICATE_INVOICE';
    case WRONG_VENDOR = 'WRONG_VENDOR';
    case WRONG_PO = 'WRONG_PO';
    case INVALID_INVOICE = 'INVALID_INVOICE';
    case OVERBILLING = 'OVERBILLING';

    public function label(): string
    {
        return match ($this) {
            self::QUANTITY_MISMATCH => 'Jumlah Tidak Sesuai',
            self::PRICE_MISMATCH => 'Harga Tidak Sesuai',
            self::TAX_MISMATCH => 'Pajak Tidak Sesuai',
            self::MISSING_RECEIVING => 'Penerimaan Belum Ada',
            self::DUPLICATE_INVOICE => 'Invoice Ganda',
            self::WRONG_VENDOR => 'Vendor Salah',
            self::WRONG_PO => 'PO Salah',
            self::INVALID_INVOICE => 'Invoice Tidak Valid',
            self::OVERBILLING => 'Kelebihan Tagih',
        };
    }
}
