<?php

namespace App\Modules\LMS\Domain\Enums;

enum CertificateStatus: string
{
    case ELIGIBLE = 'ELIGIBLE';
    case ISSUED = 'ISSUED';
    case REVOKED = 'REVOKED';

    public function label(): string
    {
        return match ($this) {
            self::ELIGIBLE => 'Memenuhi syarat',
            self::ISSUED => 'Terbit',
            self::REVOKED => 'Dicabut',
        };
    }

    public function isValid(): bool
    {
        return $this === self::ISSUED;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
