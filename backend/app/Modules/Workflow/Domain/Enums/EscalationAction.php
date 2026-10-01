<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Tindakan yang diambil mesin eskalasi (§40).
 */
enum EscalationAction: string
{
    case NOTIFY = 'NOTIFY';
    case REASSIGN = 'REASSIGN';
    case ESCALATE = 'ESCALATE';
    case MARK_OVERDUE = 'MARK_OVERDUE';

    public function label(): string
    {
        return match ($this) {
            self::NOTIFY => 'Beri Tahu Atasan',
            self::REASSIGN => 'Alihkan Tugas',
            self::ESCALATE => 'Naikkan Kewenangan',
            self::MARK_OVERDUE => 'Tandai Terlambat',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
