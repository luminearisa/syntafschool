<?php

namespace App\Modules\UKS\Domain\Enums;

enum VisitStatus: string
{
    case MENUNGGU = 'MENUNGGU';
    case DITANGANI = 'DITANGANI';
    case ISTIRAHAT = 'ISTIRAHAT';
    case PULANG = 'PULANG';
    case DIRUJUK = 'DIRUJUK';
    case SELESAI = 'SELESAI';

    public function label(): string
    {
        return match ($this) {
            self::MENUNGGU => 'Menunggu',
            self::DITANGANI => 'Ditangani',
            self::ISTIRAHAT => 'Istirahat di UKS',
            self::PULANG => 'Dipulangkan',
            self::DIRUJUK => 'Dirujuk',
            self::SELESAI => 'Selesai',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::PULANG, self::DIRUJUK, self::SELESAI], true);
    }
}

