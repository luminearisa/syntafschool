<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum RequirementType — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum RequirementType: string
{
    case AGE = 'AGE';
    case GRADE = 'GRADE';
    case PROGRAM = 'PROGRAM';
    case GENDER = 'GENDER';
    case CAPACITY = 'CAPACITY';
    case PREREQUISITE = 'PREREQUISITE';
    case SKILL = 'SKILL';
    case ATTENDANCE = 'ATTENDANCE';
    case RECOMMENDATION = 'RECOMMENDATION';
    case AUDITION = 'AUDITION';
    case TRIAL = 'TRIAL';
    case CONSENT = 'CONSENT';
    case ACADEMIC_STANDING = 'ACADEMIC_STANDING';
    case BOARDING = 'BOARDING';
    case MAX_ACTIVITIES = 'MAX_ACTIVITIES';
    case SCHEDULE_CONFLICT = 'SCHEDULE_CONFLICT';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::AGE => 'Usia',
            self::GRADE => 'Jenjang/Kelas',
            self::PROGRAM => 'Program/Jurusan',
            self::GENDER => 'Jenis Kelamin',
            self::CAPACITY => 'Kapasitas',
            self::PREREQUISITE => 'Prasyarat Aktivitas',
            self::SKILL => 'Keterampilan',
            self::ATTENDANCE => 'Kehadiran',
            self::RECOMMENDATION => 'Rekomendasi Guru',
            self::AUDITION => 'Audisi',
            self::TRIAL => 'Tes Coba',
            self::CONSENT => 'Persetujuan Orang Tua',
            self::ACADEMIC_STANDING => 'Nilai Akademik',
            self::BOARDING => 'Jadwal Asrama',
            self::MAX_ACTIVITIES => 'Batas Jumlah Aktivitas',
            self::SCHEDULE_CONFLICT => 'Bentrok Jadwal',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
