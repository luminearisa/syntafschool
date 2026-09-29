<?php

namespace App\Modules\LMS\Domain\Enums;

enum ReviewStatus: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu moderasi',
            self::APPROVED => 'Tampil',
            self::REJECTED => 'Disembunyikan',
        };
    }

    public function isPublic(): bool
    {
        return $this === self::APPROVED;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
