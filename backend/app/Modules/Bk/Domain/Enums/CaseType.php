<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Jenis penanganan kasus BK.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum CaseType: string
{
    case INDIVIDUAL_COUNSELING = 'INDIVIDUAL_COUNSELING';
    case ACADEMIC_SUPPORT = 'ACADEMIC_SUPPORT';
    case BEHAVIORAL_SUPPORT = 'BEHAVIORAL_SUPPORT';
    case SOCIAL_SUPPORT = 'SOCIAL_SUPPORT';
    case FAMILY_SUPPORT = 'FAMILY_SUPPORT';
    case CAREER_GUIDANCE = 'CAREER_GUIDANCE';
    case EDUCATIONAL_PLANNING = 'EDUCATIONAL_PLANNING';
    case BOARDING_ADJUSTMENT = 'BOARDING_ADJUSTMENT';
    case ATTENDANCE_INTERVENTION = 'ATTENDANCE_INTERVENTION';
    case DISCIPLINE_INTERVENTION = 'DISCIPLINE_INTERVENTION';
    case PEER_CONFLICT = 'PEER_CONFLICT';
    case BULLYING_CONCERN = 'BULLYING_CONCERN';
    case SAFEGUARDING_CONCERN = 'SAFEGUARDING_CONCERN';
    case CRISIS_SUPPORT = 'CRISIS_SUPPORT';
    case TRANSITION_SUPPORT = 'TRANSITION_SUPPORT';
    case DEVELOPMENT = 'DEVELOPMENT';
    case OTHER = 'OTHER';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::INDIVIDUAL_COUNSELING => 'Konseling individual',
            self::ACADEMIC_SUPPORT => 'Dukungan akademik',
            self::BEHAVIORAL_SUPPORT => 'Dukungan perilaku',
            self::SOCIAL_SUPPORT => 'Dukungan sosial',
            self::FAMILY_SUPPORT => 'Dukungan keluarga',
            self::CAREER_GUIDANCE => 'Bimbingan karier',
            self::EDUCATIONAL_PLANNING => 'Perencanaan pendidikan',
            self::BOARDING_ADJUSTMENT => 'Penyesuaian asrama',
            self::ATTENDANCE_INTERVENTION => 'Intervensi kehadiran',
            self::DISCIPLINE_INTERVENTION => 'Intervensi kedisiplinan',
            self::PEER_CONFLICT => 'Konflik antar teman',
            self::BULLYING_CONCERN => 'Kekhawatiran perundungan',
            self::SAFEGUARDING_CONCERN => 'Kekhawatiran perlindungan anak',
            self::CRISIS_SUPPORT => 'Dukungan krisis',
            self::TRANSITION_SUPPORT => 'Dukungan transisi',
            self::DEVELOPMENT => 'Pengembangan diri',
            self::OTHER => 'Lain-lain',
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

    /** Jenis yang wajib melibatkan koordinator sejak awal (perlindungan anak/krisis). */
    public function requiresCoordinatorOversight(): bool
    {
        return in_array($this, [self::SAFEGUARDING_CONCERN, self::CRISIS_SUPPORT, self::BULLYING_CONCERN], true);
    }

    /** Tingkat kerahasiaan bawaan untuk jenis kasus ini. */
    public function defaultConfidentiality(): ConfidentialityLevel
    {
        return match ($this) {
            self::SAFEGUARDING_CONCERN, self::CRISIS_SUPPORT => ConfidentialityLevel::CONFIDENTIAL,
            self::BULLYING_CONCERN, self::FAMILY_SUPPORT, self::INDIVIDUAL_COUNSELING => ConfidentialityLevel::COUNSELOR_ONLY,
            default => ConfidentialityLevel::RESTRICTED,
        };
    }
}
