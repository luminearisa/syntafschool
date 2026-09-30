<?php

namespace App\Modules\UKS\Domain\Enums;

enum ScreeningType: string
{
    case UMUM = 'UMUM';
    case GIGI = 'GIGI';
    case MATA = 'MATA';
    case TBC = 'TBC';
    case GIZI = 'GIZI';
    case LAINNYA = 'LAINNYA';

    public function label(): string
    {
        return match ($this) {
            self::UMUM => 'Pemeriksaan Umum',
            self::GIGI => 'Pemeriksaan Gigi',
            self::MATA => 'Pemeriksaan Mata',
            self::TBC => 'Skrining TBC',
            self::GIZI => 'Skrining Gizi',
            self::LAINNYA => 'Lainnya',
        };
    }
}

