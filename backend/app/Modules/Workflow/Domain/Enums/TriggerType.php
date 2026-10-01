<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Cara workflow dimulai (§45).
 */
enum TriggerType: string
{
    case MANUAL = 'MANUAL';
    case EVENT = 'EVENT';
    case API = 'API';
    case STATUS_CHANGE = 'STATUS_CHANGE';
    case SCHEDULED = 'SCHEDULED';

    public function label(): string
    {
        return match ($this) {
            self::MANUAL => 'Manual (pengguna)',
            self::EVENT => 'Event ERP',
            self::API => 'Panggilan API modul',
            self::STATUS_CHANGE => 'Perubahan Status',
            self::SCHEDULED => 'Terjadwal',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
