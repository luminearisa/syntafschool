<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Cara sebuah node persetujuan dijalankan (§21).
 *
 * SEQUENTIAL — satu approver pada satu waktu, berurutan.
 * PARALLEL   — semua approver menerima tugas bersamaan.
 * HYBRID     — approver pada `tiers` berurutan; di dalam satu tier paralel.
 *
 * Pada node PARALLEL/HYBRID, hasil akhir ditentukan `QuorumPolicy`.
 */
enum ApprovalMode: string
{
    case SEQUENTIAL = 'SEQUENTIAL';
    case PARALLEL = 'PARALLEL';
    case HYBRID = 'HYBRID';

    public function label(): string
    {
        return match ($this) {
            self::SEQUENTIAL => 'Berurutan',
            self::PARALLEL => 'Paralel',
            self::HYBRID => 'Hibrida (Paralel per Tingkat)',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
