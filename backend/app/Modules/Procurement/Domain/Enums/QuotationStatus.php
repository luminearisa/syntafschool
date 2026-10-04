<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Status penawaran vendor (§13). */
enum QuotationStatus: string
{
    use EnumOptions;

    case DRAFT = 'DRAFT';
    case RECEIVED = 'RECEIVED';
    case EVALUATED = 'EVALUATED';
    case SELECTED = 'SELECTED';
    case NOT_SELECTED = 'NOT_SELECTED';
    case EXPIRED = 'EXPIRED';
    case WITHDRAWN = 'WITHDRAWN';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::RECEIVED => 'Diterima',
            self::EVALUATED => 'Dievaluasi',
            self::SELECTED => 'Dipilih',
            self::NOT_SELECTED => 'Tidak Dipilih',
            self::EXPIRED => 'Kedaluwarsa',
            self::WITHDRAWN => 'Ditarik',
        };
    }
}
