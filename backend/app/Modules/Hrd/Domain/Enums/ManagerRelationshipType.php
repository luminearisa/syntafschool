<?php

namespace App\Modules\Hrd\Domain\Enums;

/**
 * Jenis hubungan atasan (§21).
 *
 * Struktur nyata sebuah yayasan jarang berbentuk pohon murni: kepala unit
 * melapor ke yayasan tetapi secara fungsional dikoordinasi kepala sekolah, dan
 * guru yang ditunjuk memimpin proyek tetap melapor ke wakil kepala sekolah.
 * Empat jenis ini yang dipakai resolver approver ketika menentukan siapa yang
 * berhak menyetujui sebuah transaksi.
 */
enum ManagerRelationshipType: string
{
    case DIRECT = 'direct';
    case FUNCTIONAL = 'functional';
    case DOTTED_LINE = 'dotted_line';
    case ACTING = 'acting';
    case PRIMARY = 'primary';

    public function label(): string
    {
        return match ($this) {
            self::DIRECT => 'Atasan Langsung',
            self::FUNCTIONAL => 'Atasan Fungsional',
            self::DOTTED_LINE => 'Garis Putus-putus',
            self::ACTING => 'Pejabat Sementara',
            self::PRIMARY => 'Atasan Utama',
        };
    }

    /** Urutan prioritas ketika beberapa hubungan aktif sekaligus. */
    public function priority(): int
    {
        return match ($this) {
            self::PRIMARY, self::DIRECT => 1,
            self::ACTING => 2,
            self::FUNCTIONAL => 3,
            self::DOTTED_LINE => 4,
        };
    }
}
