<?php

namespace App\Modules\Supervisi\Domain\Enums;

/** Jenis penilaian kinerja formal yang dapat dihitung modul. */
enum AppraisalType: string
{
    case PKG = 'PKG';                       // 14 kompetensi, maks 56
    case PKG_BK = 'PKG_BK';                 // 17 kompetensi, maks 68
    case NKKS = 'NKKS';                     // kinerja kepala sekolah, maks 24
    case STAFF_REVIEW = 'STAFF_REVIEW';     // tinjauan pegawai berbobot 1–5
    case SKP_PERIODIK = 'SKP_PERIODIK';     // kuadran hasil kerja × perilaku kerja
    case PIP = 'PIP';                       // rencana perbaikan kinerja

    public function label(): string
    {
        return match ($this) {
            self::PKG => 'PKG — Pembelajaran',
            self::PKG_BK => 'PKG — BK/Konselor',
            self::NKKS => 'NKKS — Kepala Sekolah',
            self::STAFF_REVIEW => 'Tinjauan Kinerja Pegawai',
            self::SKP_PERIODIK => 'SKP Periodik (Kuadran)',
            self::PIP => 'Rencana Perbaikan Kinerja',
        };
    }

    /** Skor maksimum baku menurut pedoman (null = dihitung dari instrumen). */
    public function defaultMaxScore(): ?float
    {
        return match ($this) {
            self::PKG => 56.0,
            self::PKG_BK => 68.0,
            self::NKKS => 24.0,
            default => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
