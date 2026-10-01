<?php

namespace App\Modules\Asset\Domain\Enums;

enum AssetStatus: string
{
    case PLANNED = 'PLANNED';
    case ORDERED = 'ORDERED';
    case RECEIVED = 'RECEIVED';
    case REGISTERED = 'REGISTERED';
    case TAGGED = 'TAGGED';
    case ACTIVE = 'ACTIVE';
    case ASSIGNED = 'ASSIGNED';
    case IN_STORAGE = 'IN_STORAGE';
    case ON_LOAN = 'ON_LOAN';
    case IN_MAINTENANCE = 'IN_MAINTENANCE';
    case DAMAGED = 'DAMAGED';
    case LOST = 'LOST';
    case UNDER_INVESTIGATION = 'UNDER_INVESTIGATION';
    case DISPOSED = 'DISPOSED';
    case RETIRED = 'RETIRED';

    /** Status yang boleh dipinjamkan. */
    public function isLoanable(): bool
    {
        return in_array($this, [self::ACTIVE, self::ASSIGNED, self::IN_STORAGE], true);
    }

    /** Status yang masih "hidup" (dapat dipindahkan atau dipelihara). */
    public function isLive(): bool
    {
        return ! in_array($this, [self::DISPOSED, self::RETIRED], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PLANNED => 'Direncanakan',
            self::ORDERED => 'Dipesan',
            self::RECEIVED => 'Diterima',
            self::REGISTERED => 'Terdaftar',
            self::TAGGED => 'Terlabel',
            self::ACTIVE => 'Aktif',
            self::ASSIGNED => 'Ditugaskan',
            self::IN_STORAGE => 'Di gudang',
            self::ON_LOAN => 'Dipinjam',
            self::IN_MAINTENANCE => 'Dalam perawatan',
            self::DAMAGED => 'Rusak',
            self::LOST => 'Hilang',
            self::UNDER_INVESTIGATION => 'Dalam investigasi',
            self::DISPOSED => 'Dihapusbukukan',
            self::RETIRED => 'Diarsipkan',
        };
    }
}
