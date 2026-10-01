<?php

namespace App\Modules\Asset\Domain\Enums;

enum AssetCondition: string
{
    case NEW = 'NEW';
    case GOOD = 'GOOD';
    case FAIR = 'FAIR';
    case POOR = 'POOR';
    case DAMAGED = 'DAMAGED';
    case CRITICAL = 'CRITICAL';
    case UNUSABLE = 'UNUSABLE';

    /** Peringkat kondisi: makin besar makin buruk. */
    public function rank(): int
    {
        return match ($this) {
            self::NEW => 1,
            self::GOOD => 2,
            self::FAIR => 3,
            self::POOR => 4,
            self::DAMAGED => 5,
            self::CRITICAL => 6,
            self::UNUSABLE => 7,
        };
    }

    public function isUsable(): bool
    {
        return $this->rank() <= self::FAIR->rank();
    }

    /** Kondisi yang menuntut tindak lanjut pemeliharaan. */
    public function needsRepair(): bool
    {
        return $this->rank() >= self::POOR->rank();
    }

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'Baru',
            self::GOOD => 'Baik',
            self::FAIR => 'Cukup',
            self::POOR => 'Kurang',
            self::DAMAGED => 'Rusak',
            self::CRITICAL => 'Kritis',
            self::UNUSABLE => 'Tidak dapat dipakai',
        };
    }
}
