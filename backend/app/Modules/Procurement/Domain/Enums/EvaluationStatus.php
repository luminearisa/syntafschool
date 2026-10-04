<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Status evaluasi penawaran (§15). */
enum EvaluationStatus: string
{
    use EnumOptions;

    case DRAFT = 'DRAFT';
    case IN_PROGRESS = 'IN_PROGRESS';
    case COMPLETED = 'COMPLETED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::IN_PROGRESS => 'Sedang Dinilai',
            self::COMPLETED => 'Selesai',
        };
    }
}
