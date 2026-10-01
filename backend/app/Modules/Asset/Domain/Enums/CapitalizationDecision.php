<?php

namespace App\Modules\Asset\Domain\Enums;

enum CapitalizationDecision: string
{
    case ASSET = 'ASSET';
    case INVENTORY = 'INVENTORY';
    case EXPENSE = 'EXPENSE';

    public function label(): string
    {
        return match ($this) {
            self::ASSET => 'Aset (dapat disusutkan)',
            self::INVENTORY => 'Persediaan',
            self::EXPENSE => 'Beban langsung',
        };
    }
}
