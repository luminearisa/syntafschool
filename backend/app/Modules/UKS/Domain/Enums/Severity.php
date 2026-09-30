<?php

namespace App\Modules\UKS\Domain\Enums;

enum Severity: string
{
    case RINGAN = 'RINGAN';
    case SEDANG = 'SEDANG';
    case BERAT = 'BERAT';
    case DARURAT = 'DARURAT';

    public function label(): string
    {
        return match ($this) {
            self::RINGAN => 'Ringan',
            self::SEDANG => 'Sedang',
            self::BERAT => 'Berat',
            self::DARURAT => 'Darurat',
        };
    }

    /** Tingkat yang mewajibkan orang tua diberitahu. */
    public function requiresParentNotice(): bool
    {
        return $this === self::BERAT || $this === self::DARURAT;
    }
}

