<?php

namespace App\Modules\Supervisi\Domain\Enums;

/** Kategori instrumen supervisi. */
enum InstrumentCategory: string
{
    case AKADEMIK = 'AKADEMIK';                 // guru: perencanaan, pelaksanaan, penilaian
    case MANAJERIAL = 'MANAJERIAL';             // tenaga kependidikan / administrasi sekolah
    case KEPALA_SEKOLAH = 'KEPALA_SEKOLAH';     // NKKS & supervisi pengawas
    case PKG = 'PKG';                           // penilaian kinerja guru resmi
    case KINERJA_PEGAWAI = 'KINERJA_PEGAWAI';   // tinjauan kinerja 1–5 berbobot
    case PIP = 'PIP';                           // rencana perbaikan kinerja
    case EVALUASI_PROGRAM = 'EVALUASI_PROGRAM'; // CIPP program supervisi
    case SURVEI = 'SURVEI';                     // umpan balik siswa/sejawat (opsional)

    public function label(): string
    {
        return match ($this) {
            self::AKADEMIK => 'Supervisi Akademik',
            self::MANAJERIAL => 'Supervisi Manajerial / Tendik',
            self::KEPALA_SEKOLAH => 'Kinerja Kepala Sekolah',
            self::PKG => 'Penilaian Kinerja Guru',
            self::KINERJA_PEGAWAI => 'Tinjauan Kinerja Pegawai',
            self::PIP => 'Rencana Perbaikan Kinerja',
            self::EVALUASI_PROGRAM => 'Evaluasi Program',
            self::SURVEI => 'Survei / Umpan Balik',
        };
    }

    public function isAppraisal(): bool
    {
        return in_array($this, [self::PKG, self::KINERJA_PEGAWAI, self::KEPALA_SEKOLAH], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
