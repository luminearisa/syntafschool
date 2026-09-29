<?php

namespace App\Modules\Supervisi\Domain\Enums;

/** Siapa yang dinilai dengan instrumen ini. */
enum InstrumentTargetType: string
{
    case GURU = 'GURU';
    case TENDIK = 'TENDIK';         // tenaga kependidikan / karyawan
    case KEPALA_SEKOLAH = 'KEPALA_SEKOLAH';
    case UMUM = 'UMUM';             // berlaku untuk semua jenis pegawai

    public function label(): string
    {
        return match ($this) {
            self::GURU => 'Guru',
            self::TENDIK => 'Tenaga Kependidikan',
            self::KEPALA_SEKOLAH => 'Kepala Sekolah',
            self::UMUM => 'Semua Pegawai',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
