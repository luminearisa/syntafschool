<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Hasil akhir sebuah step instance.
 */
enum StepResult: string
{
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case RETURNED = 'RETURNED';
    case CHANGES_REQUESTED = 'CHANGES_REQUESTED';
    case COMPLETED = 'COMPLETED';
    case SKIPPED = 'SKIPPED';
    case CANCELLED = 'CANCELLED';
    case FAILED = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::APPROVED => 'Disetujui',
            self::REJECTED => 'Ditolak',
            self::RETURNED => 'Dikembalikan',
            self::CHANGES_REQUESTED => 'Diminta Perubahan',
            self::COMPLETED => 'Selesai',
            self::SKIPPED => 'Dilewati',
            self::CANCELLED => 'Dibatalkan',
            self::FAILED => 'Gagal',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
