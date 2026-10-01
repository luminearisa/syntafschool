<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Jenis intervensi yang dapat dilaksanakan.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum InterventionType: string
{
    case COUNSELING = 'COUNSELING';
    case MENTORING = 'MENTORING';
    case ACADEMIC_SUPPORT = 'ACADEMIC_SUPPORT';
    case BEHAVIORAL_COACHING = 'BEHAVIORAL_COACHING';
    case PARENT_MEETING = 'PARENT_MEETING';
    case TEACHER_COORDINATION = 'TEACHER_COORDINATION';
    case PEER_SUPPORT = 'PEER_SUPPORT';
    case BOARDING_INTERVENTION = 'BOARDING_INTERVENTION';
    case ACTIVITY_INTERVENTION = 'ACTIVITY_INTERVENTION';
    case ATTENDANCE_INTERVENTION = 'ATTENDANCE_INTERVENTION';
    case STUDY_PLAN = 'STUDY_PLAN';
    case CAREER_GUIDANCE = 'CAREER_GUIDANCE';
    case REFERRAL = 'REFERRAL';
    case ENVIRONMENTAL_ADJUSTMENT = 'ENVIRONMENTAL_ADJUSTMENT';
    case MONITORING = 'MONITORING';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::COUNSELING => 'Konseling',
            self::MENTORING => 'Pendampingan',
            self::ACADEMIC_SUPPORT => 'Dukungan akademik',
            self::BEHAVIORAL_COACHING => 'Pembinaan perilaku',
            self::PARENT_MEETING => 'Pertemuan orang tua',
            self::TEACHER_COORDINATION => 'Koordinasi guru',
            self::PEER_SUPPORT => 'Dukungan teman sebaya',
            self::BOARDING_INTERVENTION => 'Intervensi asrama',
            self::ACTIVITY_INTERVENTION => 'Intervensi aktivitas',
            self::ATTENDANCE_INTERVENTION => 'Intervensi kehadiran',
            self::STUDY_PLAN => 'Rencana belajar',
            self::CAREER_GUIDANCE => 'Bimbingan karier',
            self::REFERRAL => 'Rujukan',
            self::ENVIRONMENTAL_ADJUSTMENT => 'Penyesuaian lingkungan',
            self::MONITORING => 'Pemantauan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
