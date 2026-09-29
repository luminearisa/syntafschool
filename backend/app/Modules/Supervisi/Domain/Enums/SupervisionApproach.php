<?php

namespace App\Modules\Supervisi\Domain\Enums;

/**
 * Gaya interpersonal supervisor (Glickman) — dipilih sesuai tingkat
 * perkembangan guru/pegawai, bukan selera penilai.
 */
enum SupervisionApproach: string
{
    case DIRECTIVE_CONTROL = 'DIRECTIVE_CONTROL';
    case DIRECTIVE_INFORMATIONAL = 'DIRECTIVE_INFORMATIONAL';
    case COLLABORATIVE = 'COLLABORATIVE';
    case NONDIRECTIVE = 'NONDIRECTIVE';

    public function label(): string
    {
        return match ($this) {
            self::DIRECTIVE_CONTROL => 'Direktif (Pengawasan)',
            self::DIRECTIVE_INFORMATIONAL => 'Direktif (Informasi)',
            self::COLLABORATIVE => 'Kolaboratif',
            self::NONDIRECTIVE => 'Non-direktif',
        };
    }

    public function guidance(): string
    {
        return match ($this) {
            self::DIRECTIVE_CONTROL => 'Pegawai baru/bermasalah berat: supervisor mengarahkan langkah perbaikan secara rinci.',
            self::DIRECTIVE_INFORMATIONAL => 'Supervisor menjelaskan pilihan dan konsekuensinya, pegawai memilih.',
            self::COLLABORATIVE => 'Kapasitas menengah: keputusan dan solusi disusun bersama.',
            self::NONDIRECTIVE => 'Pegawai sudah mandiri: supervisor memfasilitasi refleksi dan pertanyaan.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
