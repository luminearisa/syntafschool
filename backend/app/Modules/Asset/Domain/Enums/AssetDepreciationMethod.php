<?php

namespace App\Modules\Asset\Domain\Enums;

enum AssetDepreciationMethod: string
{
    case STRAIGHT_LINE = 'STRAIGHT_LINE';
    case DECLINING_BALANCE = 'DECLINING_BALANCE';
    case CUSTOM = 'CUSTOM';

    /** Nama metode pada modul Finance — dijembatani kontrak, bukan dipaksa sama. */
    public function financeMethod(): string
    {
        return $this->value;
    }
}
