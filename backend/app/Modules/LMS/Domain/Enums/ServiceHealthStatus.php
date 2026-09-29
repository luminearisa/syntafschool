<?php

namespace App\Modules\LMS\Domain\Enums;

/** Status kesehatan layanan (§42). */
enum ServiceHealthStatus: string
{
    case HEALTHY = 'HEALTHY';
    case DEGRADED = 'DEGRADED';
    case UNAVAILABLE = 'UNAVAILABLE';

    public function label(): string
    {
        return match ($this) {
            self::HEALTHY => 'Normal',
            self::DEGRADED => 'Terbatas',
            self::UNAVAILABLE => 'Tidak tersedia',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::HEALTHY => 'green',
            self::DEGRADED => 'amber',
            self::UNAVAILABLE => 'red',
        };
    }

    public function allowsLearning(): bool
    {
        return $this === self::HEALTHY || $this === self::DEGRADED;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
