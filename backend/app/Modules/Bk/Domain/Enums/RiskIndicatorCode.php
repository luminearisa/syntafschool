<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Kode penanda dukungan.
 *
 * Setiap kode menyebut **pengamatan**, bukan kesimpulan: "absen berulang" adalah
 * fakta yang dapat diverifikasi, sedangkan label klinis bukan urusan modul ini.
 */
enum RiskIndicatorCode: string
{
    case REPEATED_ABSENCE = 'REPEATED_ABSENCE';
    case ACADEMIC_DECLINE = 'ACADEMIC_DECLINE';
    case REPEATED_DISCIPLINE_INCIDENT = 'REPEATED_DISCIPLINE_INCIDENT';
    case MULTIPLE_REFERRALS = 'MULTIPLE_REFERRALS';
    case SUDDEN_WITHDRAWAL = 'SUDDEN_WITHDRAWAL';
    case BOARDING_ADJUSTMENT_CONCERN = 'BOARDING_ADJUSTMENT_CONCERN';
    case REPEATED_FOLLOW_UP_FAILURE = 'REPEATED_FOLLOW_UP_FAILURE';
    case CLINIC_REPEAT_VISIT = 'CLINIC_REPEAT_VISIT';
    case ACTIVITY_DISENGAGEMENT = 'ACTIVITY_DISENGAGEMENT';
    case ATTENDANCE_PATTERN_CHANGE = 'ATTENDANCE_PATTERN_CHANGE';
    case CUSTOM = 'CUSTOM';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::REPEATED_ABSENCE => 'Absen berulang',
            self::ACADEMIC_DECLINE => 'Penurunan capaian akademik',
            self::REPEATED_DISCIPLINE_INCIDENT => 'Pelanggaran berulang',
            self::MULTIPLE_REFERRALS => 'Beberapa rujukan dalam waktu dekat',
            self::SUDDEN_WITHDRAWAL => 'Penarikan diri mendadak',
            self::BOARDING_ADJUSTMENT_CONCERN => 'Penyesuaian asrama perlu perhatian',
            self::REPEATED_FOLLOW_UP_FAILURE => 'Tindak lanjut yang gagal berulang',
            self::CLINIC_REPEAT_VISIT => 'Kunjungan klinik berulang',
            self::ACTIVITY_DISENGAGEMENT => 'Keterlibatan kegiatan menurun',
            self::ATTENDANCE_PATTERN_CHANGE => 'Perubahan pola kehadiran',
            self::CUSTOM => 'Penanda khusus sekolah',
        };
    }

    /** Modul sumber yang paling lazim menjadi asal penanda ini. */
    public function defaultSource(): RiskSourceModule
    {
        return match ($this) {
            self::REPEATED_ABSENCE, self::ATTENDANCE_PATTERN_CHANGE => RiskSourceModule::ATTENDANCE,
            self::ACADEMIC_DECLINE => RiskSourceModule::ACADEMIC,
            self::REPEATED_DISCIPLINE_INCIDENT => RiskSourceModule::DISCIPLINE,
            self::BOARDING_ADJUSTMENT_CONCERN, self::SUDDEN_WITHDRAWAL => RiskSourceModule::BOARDING,
            self::CLINIC_REPEAT_VISIT => RiskSourceModule::CLINIC,
            self::ACTIVITY_DISENGAGEMENT => RiskSourceModule::ACTIVITY,
            self::MULTIPLE_REFERRALS => RiskSourceModule::SYSTEM,
            self::REPEATED_FOLLOW_UP_FAILURE => RiskSourceModule::SYSTEM,
            self::CUSTOM => RiskSourceModule::SYSTEM,
        };
    }

    /** Ringkasan yang aman ditampilkan kepada pihak non-klinis. */
    public function neutralNote(): string
    {
        return match ($this) {
            self::REPEATED_ABSENCE, self::ATTENDANCE_PATTERN_CHANGE => 'Perlu ditanyakan apa yang membuat kehadirannya berubah.',
            self::ACADEMIC_DECLINE => 'Perlu ditelusuri hambatan belajar yang mungkin muncul.',
            self::REPEATED_DISCIPLINE_INCIDENT => 'Perlu ditinjau ulang pendekatan yang dipakai selama ini.',
            self::MULTIPLE_REFERRALS => 'Beberapa pihak melaporkan hal serupa; perlu dikoordinasikan.',
            self::SUDDEN_WITHDRAWAL => 'Perlu didekati dengan hati-hati dan tanpa menghakimi.',
            self::BOARDING_ADJUSTMENT_CONCERN => 'Perlu dibicarakan dengan pengasuh dan siswa.',
            self::REPEATED_FOLLOW_UP_FAILURE => 'Perlu ditinjau ulang bentuk dukungan yang diberikan.',
            self::CLINIC_REPEAT_VISIT => 'Perlu dikoordinasikan dengan petugas klinik.',
            self::ACTIVITY_DISENGAGEMENT => 'Perlu ditawarkan kegiatan yang sesuai minatnya.',
            default => 'Perlu ditinjau tim BK.',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
