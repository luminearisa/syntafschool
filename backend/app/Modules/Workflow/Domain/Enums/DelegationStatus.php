<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Status delegasi kewenangan approver (§8, §39).
 */
enum DelegationStatus: string
{
    case PENDING = 'PENDING';
    case ACTIVE = 'ACTIVE';
    case REVOKED = 'REVOKED';
    case EXPIRED = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Terjadwal',
            self::ACTIVE => 'Aktif',
            self::REVOKED => 'Dicabut',
            self::EXPIRED => 'Berakhir',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
