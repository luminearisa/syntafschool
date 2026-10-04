<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Alur penanganan pengecualian invoice (§30). */
enum ExceptionStatus: string
{
    use EnumOptions;

    case DETECTED = 'DETECTED';
    case INVESTIGATION = 'INVESTIGATION';
    case RESOLVED = 'RESOLVED';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case CLOSED = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::DETECTED => 'Terdeteksi',
            self::INVESTIGATION => 'Investigasi',
            self::RESOLVED => 'Terselesaikan',
            self::APPROVED => 'Disetujui',
            self::REJECTED => 'Ditolak',
            self::CLOSED => 'Ditutup',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::DETECTED, self::INVESTIGATION], true);
    }
}
