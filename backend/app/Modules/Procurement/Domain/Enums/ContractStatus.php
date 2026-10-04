<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Status kontrak / perjanjian kerangka vendor (§36). */
enum ContractStatus: string
{
    use EnumOptions;

    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case EXPIRING = 'EXPIRING';
    case EXPIRED = 'EXPIRED';
    case TERMINATED = 'TERMINATED';
    case RENEWED = 'RENEWED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::ACTIVE => 'Aktif',
            self::EXPIRING => 'Segera Berakhir',
            self::EXPIRED => 'Berakhir',
            self::TERMINATED => 'Dihentikan',
            self::RENEWED => 'Diperbarui',
        };
    }
}
