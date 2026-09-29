<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Cara sebuah learning path membuka modulnya (§2).
 *
 * Mode hanya mengubah *urutan pembukaan*, bukan cara progress dihitung:
 * seluruh mode tetap memakai mesin completion/progress Phase 1.
 */
enum LearningPathMode: string
{
    case SEQUENTIAL = 'SEQUENTIAL';
    case FLEXIBLE = 'FLEXIBLE';
    case SELF_PACED = 'SELF_PACED';

    public function label(): string
    {
        return match ($this) {
            self::SEQUENTIAL => 'Berurutan (modul terbuka satu per satu)',
            self::FLEXIBLE => 'Fleksibel (bebas memilih materi)',
            self::SELF_PACED => 'Mandiri (tanpa batas waktu)',
        };
    }

    public function locksNextModule(): bool
    {
        return $this === self::SEQUENTIAL;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
