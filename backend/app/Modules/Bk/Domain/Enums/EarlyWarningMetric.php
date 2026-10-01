<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Metrik yang dapat dipantau mesin aturan.
 *
 * Daftar ini adalah satu-satunya "kosakata" yang dikenal mesin; setiap metrik
 * mengambil datanya dari penyedia modul pemiliknya, bukan dari salinan lokal.
 */
enum EarlyWarningMetric: string
{
    case ATTENDANCE_RATE = 'ATTENDANCE_RATE';
    case ABSENCE_COUNT = 'ABSENCE_COUNT';
    case LATE_COUNT = 'LATE_COUNT';
    case DISCIPLINE_COUNT = 'DISCIPLINE_COUNT';
    case DISCIPLINE_POINTS = 'DISCIPLINE_POINTS';
    case ACADEMIC_AVERAGE = 'ACADEMIC_AVERAGE';
    case ACADEMIC_CHANGE = 'ACADEMIC_CHANGE';
    case SUBJECT_BELOW_THRESHOLD_COUNT = 'SUBJECT_BELOW_THRESHOLD_COUNT';
    case REFERRAL_COUNT = 'REFERRAL_COUNT';
    case FOLLOW_UP_FAILURE_COUNT = 'FOLLOW_UP_FAILURE_COUNT';
    case CLINIC_VISIT_COUNT = 'CLINIC_VISIT_COUNT';
    case BOARDING_ADJUSTMENT_COUNT = 'BOARDING_ADJUSTMENT_COUNT';
    case ACTIVITY_DISENGAGEMENT_DAYS = 'ACTIVITY_DISENGAGEMENT_DAYS';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::ATTENDANCE_RATE => 'Persentase kehadiran',
            self::ABSENCE_COUNT => 'Jumlah absen',
            self::LATE_COUNT => 'Jumlah keterlambatan',
            self::DISCIPLINE_COUNT => 'Jumlah pelanggaran',
            self::DISCIPLINE_POINTS => 'Poin pelanggaran',
            self::ACADEMIC_AVERAGE => 'Rata-rata nilai',
            self::ACADEMIC_CHANGE => 'Perubahan nilai',
            self::SUBJECT_BELOW_THRESHOLD_COUNT => 'Jumlah mapel di bawah ambang',
            self::REFERRAL_COUNT => 'Jumlah rujukan',
            self::FOLLOW_UP_FAILURE_COUNT => 'Tindak lanjut gagal',
            self::CLINIC_VISIT_COUNT => 'Kunjungan klinik',
            self::BOARDING_ADJUSTMENT_COUNT => 'Penyesuaian asrama',
            self::ACTIVITY_DISENGAGEMENT_DAYS => 'Hari tanpa kegiatan',
        };
    }

    /** Satuan bawaan untuk tampilan ambang. */
    public function unit(): string
    {
        return match ($this) {
            self::ATTENDANCE_RATE => 'PERCENT',
            self::ACADEMIC_AVERAGE, self::ACADEMIC_CHANGE, self::DISCIPLINE_POINTS => 'POINT',
            self::ACTIVITY_DISENGAGEMENT_DAYS => 'DAY',
            default => 'COUNT',
        };
    }

    /** Semakin besar semakin baik? Dipakai untuk memilih arti "memburuk" bawaan. */
    public function higherIsBetter(): bool
    {
        return in_array($this, [self::ATTENDANCE_RATE, self::ACADEMIC_AVERAGE, self::ACADEMIC_CHANGE], true);
    }

    /** Modul pemilik data metrik ini. */
    public function sourceModule(): RiskSourceModule
    {
        return match ($this) {
            self::ATTENDANCE_RATE, self::ABSENCE_COUNT, self::LATE_COUNT => RiskSourceModule::ATTENDANCE,
            self::ACADEMIC_AVERAGE, self::ACADEMIC_CHANGE, self::SUBJECT_BELOW_THRESHOLD_COUNT => RiskSourceModule::ACADEMIC,
            self::DISCIPLINE_COUNT, self::DISCIPLINE_POINTS => RiskSourceModule::DISCIPLINE,
            self::CLINIC_VISIT_COUNT => RiskSourceModule::CLINIC,
            self::BOARDING_ADJUSTMENT_COUNT => RiskSourceModule::BOARDING,
            self::ACTIVITY_DISENGAGEMENT_DAYS => RiskSourceModule::ACTIVITY,
            self::REFERRAL_COUNT, self::FOLLOW_UP_FAILURE_COUNT => RiskSourceModule::SYSTEM,
        };
    }

    /** Operator yang masuk akal untuk metrik ini (untuk validasi aturan). */
    public function suggestedOperator(): EarlyWarningOperator
    {
        return $this->higherIsBetter() ? EarlyWarningOperator::LT : EarlyWarningOperator::GTE;
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
