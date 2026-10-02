<?php

namespace App\Modules\Hrd\Domain\Enums;

/**
 * Jenis penugasan (§20).
 *
 * Satu pegawai dapat memiliki penugasan utama (Guru Matematika), penugasan kedua
 * (Pembina OSIS), penugasan sementara (pengganti 3 bulan), pejabat sementara
 * (Plt. Kepala Unit), dan penugasan proyek sekaligus. Hanya satu yang boleh
 * bertanda `PRIMARY` pada satu waktu.
 */
enum AssignmentType: string
{
    case PRIMARY = 'primary';
    case SECONDARY = 'secondary';
    case TEMPORARY = 'temporary';
    case ACTING = 'acting';
    case PROJECT = 'project';

    public function label(): string
    {
        return match ($this) {
            self::PRIMARY => 'Penugasan Utama',
            self::SECONDARY => 'Penugasan Tambahan',
            self::TEMPORARY => 'Penugasan Sementara',
            self::ACTING => 'Pejabat Sementara',
            self::PROJECT => 'Penugasan Proyek',
        };
    }

    public function isPrimary(): bool
    {
        return $this === self::PRIMARY;
    }

    /** Penugasan sementara wajib punya tanggal berakhir. */
    public function requiresEndDate(): bool
    {
        return in_array($this, [self::TEMPORARY, self::ACTING, self::PROJECT], true);
    }
}
