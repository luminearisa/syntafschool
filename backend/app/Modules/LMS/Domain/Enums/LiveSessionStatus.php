<?php

namespace App\Modules\LMS\Domain\Enums;

enum LiveSessionStatus: string
{
    case SCHEDULED = 'SCHEDULED';
    case LIVE = 'LIVE';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::SCHEDULED => 'Terjadwal',
            self::LIVE => 'Berlangsung',
            self::COMPLETED => 'Selesai',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::SCHEDULED, self::LIVE], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
