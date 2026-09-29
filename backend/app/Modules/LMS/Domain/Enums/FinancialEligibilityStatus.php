<?php

namespace App\Modules\LMS\Domain\Enums;

/** Status kelayakan belajar dari sisi keuangan (§8). */
enum FinancialEligibilityStatus: string
{
    case ELIGIBLE = 'ELIGIBLE';
    case RESTRICTED = 'RESTRICTED';
    case SUSPENDED = 'SUSPENDED';
    case UNKNOWN = 'UNKNOWN';

    public function label(): string
    {
        return match ($this) {
            self::ELIGIBLE => 'Layak',
            self::RESTRICTED => 'Dibatasi',
            self::SUSPENDED => 'Ditangguhkan',
            self::UNKNOWN => 'Tidak diketahui',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::ELIGIBLE => 'green',
            self::RESTRICTED => 'amber',
            self::SUSPENDED => 'red',
            self::UNKNOWN => 'gray',
        };
    }

    /** Status yang membuat kebijakan pembatasan perlu ditanyakan (§8). */
    public function isBlocking(): bool
    {
        return $this === self::RESTRICTED || $this === self::SUSPENDED;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
