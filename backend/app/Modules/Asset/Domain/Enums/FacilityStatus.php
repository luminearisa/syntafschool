<?php

namespace App\Modules\Asset\Domain\Enums;

enum FacilityStatus: string
{
    case PLANNED = 'PLANNED';
    case ACTIVE = 'ACTIVE';
    case LIMITED = 'LIMITED';
    case MAINTENANCE = 'MAINTENANCE';
    case CLOSED = 'CLOSED';
    case RETIRED = 'RETIRED';

    /** Fasilitas yang boleh dibooking. */
    public function isBookable(): bool
    {
        return in_array($this, [self::ACTIVE, self::LIMITED], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PLANNED => 'Direncanakan',
            self::ACTIVE => 'Aktif',
            self::LIMITED => 'Terbatas',
            self::MAINTENANCE => 'Pemeliharaan',
            self::CLOSED => 'Ditutup',
            self::RETIRED => 'Diarsipkan',
        };
    }
}
