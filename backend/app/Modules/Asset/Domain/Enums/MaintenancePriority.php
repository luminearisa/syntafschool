<?php

namespace App\Modules\Asset\Domain\Enums;

enum MaintenancePriority: string
{
    case LOW = 'LOW';
    case NORMAL = 'NORMAL';
    case HIGH = 'HIGH';
    case URGENT = 'URGENT';
    case CRITICAL = 'CRITICAL';

    public function rank(): int
    {
        return match ($this) {
            self::LOW => 1,
            self::NORMAL => 2,
            self::HIGH => 3,
            self::URGENT => 4,
            self::CRITICAL => 5,
        };
    }

    /** Batas waktu tanggap (jam) — dari konfigurasi, bukan di dalam kode. */
    public function responseHours(): int
    {
        return (int) config('asset.sla.response_hours.'.$this->value, 24);
    }

    /** Batas waktu penyelesaian (jam). */
    public function workOrderHours(): int
    {
        return (int) config('asset.sla.work_order_hours.'.$this->value, 120);
    }
}
