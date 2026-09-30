<?php

namespace App\Modules\UKS\Domain\Enums;

enum ConsentDecision: string
{
    case DISETUJUI = 'DISETUJUI';
    case DITOLAK = 'DITOLAK';

    public function label(): string
    {
        return $this === self::DISETUJUI ? 'Disetujui' : 'Ditolak';
    }
}

