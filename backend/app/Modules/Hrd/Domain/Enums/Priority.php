<?php

namespace App\Modules\Hrd\Domain\Enums;

/**
 * Prioritas transaksi/permintaan HR (§33).
 *
 * Dipakai untuk mengurutkan antrean persetujuan dan menentukan tenggat SLA.
 * Nilainya sengaja sedikit: prioritas yang terlalu banyak tidak lagi berarti
 * apa pun.
 */
enum Priority: string
{
    case LOW = 'low';
    case NORMAL = 'normal';
    case HIGH = 'high';
    case URGENT = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::LOW => 'Rendah',
            self::NORMAL => 'Normal',
            self::HIGH => 'Tinggi',
            self::URGENT => 'Mendesak',
        };
    }

    /** Bobot untuk pengurutan antrean: makin besar makin didahulukan. */
    public function weight(): int
    {
        return match ($this) {
            self::LOW => 1,
            self::NORMAL => 2,
            self::HIGH => 3,
            self::URGENT => 4,
        };
    }

    /** Batas waktu penyelesaian (jam) bila jenis permintaan tidak menentukannya. */
    public function defaultSlaHours(): int
    {
        return match ($this) {
            self::LOW => 120,
            self::NORMAL => 72,
            self::HIGH => 24,
            self::URGENT => 8,
        };
    }
}
