<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Status banding nilai siswa (§20, §59).
 */
enum GradeReviewStatus: string
{
    case PENDING = 'PENDING';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case CLOSED = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu',
            self::UNDER_REVIEW => 'Sedang ditinjau',
            self::APPROVED => 'Disetujui',
            self::REJECTED => 'Ditolak',
            self::CLOSED => 'Ditutup',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::PENDING => 'amber',
            self::UNDER_REVIEW => 'blue',
            self::APPROVED => 'green',
            self::REJECTED => 'red',
            self::CLOSED => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::PENDING, self::UNDER_REVIEW], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
