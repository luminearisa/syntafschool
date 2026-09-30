<?php

namespace App\Modules\UKS\Domain\Enums;

enum LetterType: string
{
    case ISTIRAHAT = 'ISTIRAHAT';
    case SAKIT = 'SAKIT';
    case RUJUKAN = 'RUJUKAN';

    public function label(): string
    {
        return match ($this) {
            self::ISTIRAHAT => 'Surat Istirahat',
            self::SAKIT => 'Surat Keterangan Sakit',
            self::RUJUKAN => 'Surat Rujukan',
        };
    }
}
