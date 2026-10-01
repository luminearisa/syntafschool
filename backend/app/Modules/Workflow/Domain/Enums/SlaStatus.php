<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Status SLA sebuah step (§42).
 */
enum SlaStatus: string
{
    case ON_TIME = 'ON_TIME';
    case DUE_SOON = 'DUE_SOON';
    case OVERDUE = 'OVERDUE';
    case ESCALATED = 'ESCALATED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    public function isOpen(): bool
    {
        return in_array($this, [self::ON_TIME, self::DUE_SOON, self::OVERDUE, self::ESCALATED], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::ON_TIME => 'Tepat Waktu',
            self::DUE_SOON => 'Segera Jatuh Tempo',
            self::OVERDUE => 'Terlambat',
            self::ESCALATED => 'Tereksekusi',
            self::COMPLETED => 'Selesai',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
