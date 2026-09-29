<?php

namespace App\Modules\Supervisi\Domain\Enums;

/** Jenis supervisor yang boleh dinominasikan sekolah. */
enum SupervisorKind: string
{
    case KEPALA_SEKOLAH = 'KEPALA_SEKOLAH';
    case WAKASEK = 'WAKASEK';
    case KOORDINATOR = 'KOORDINATOR';   // koordinator mapel / unit
    case PENGAWAS = 'PENGAWAS';         // pengawas sekolah (eksternal satuan)
    case MENTOR = 'MENTOR';             // pendamping guru baru
    case PEER = 'PEER';                 // supervisi sejawat / lesson study
    case EKSTERNAL = 'EKSTERNAL';       // asesor luar (mis. yayasan, akreditasi)

    public function label(): string
    {
        return match ($this) {
            self::KEPALA_SEKOLAH => 'Kepala Sekolah',
            self::WAKASEK => 'Wakil Kepala Sekolah',
            self::KOORDINATOR => 'Koordinator',
            self::PENGAWAS => 'Pengawas Sekolah',
            self::MENTOR => 'Mentor / Pendamping',
            self::PEER => 'Rekan Sejawat',
            self::EKSTERNAL => 'Asesor Eksternal',
        };
    }

    /**
     * Hanya supervisor dengan kewenangan penilaian resmi yang boleh memfinalkan
     * nilai yang masuk ke dokumen sekolah (§A4 keputusan desain).
     */
    public function mayFinalize(): bool
    {
        return in_array($this, [self::KEPALA_SEKOLAH, self::WAKASEK, self::PENGAWAS, self::EKSTERNAL], true);
    }

    /** Boleh menghitung/menerbitkan penilaian kinerja (PKG/NKKS/tinjauan pegawai). */
    public function mayAppraise(): bool
    {
        return in_array($this, [self::KEPALA_SEKOLAH, self::PENGAWAS, self::EKSTERNAL], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
