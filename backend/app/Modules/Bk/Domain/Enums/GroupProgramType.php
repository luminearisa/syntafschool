<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Jenis program kelompok BK.
 *
 * Program adalah layanan pencegahan/pengembangan, jadi ia tidak mensyaratkan kasus.
 */
enum GroupProgramType: string
{
    case STUDY_SKILLS = 'STUDY_SKILLS';
    case LEADERSHIP = 'LEADERSHIP';
    case CAREER_PREPARATION = 'CAREER_PREPARATION';
    case BOARDING_TRANSITION = 'BOARDING_TRANSITION';
    case EXAM_PREPARATION = 'EXAM_PREPARATION';
    case SOCIAL_SKILLS = 'SOCIAL_SKILLS';
    case CHARACTER_DEVELOPMENT = 'CHARACTER_DEVELOPMENT';
    case OTHER = 'OTHER';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::STUDY_SKILLS => 'Keterampilan belajar',
            self::LEADERSHIP => 'Kepemimpinan',
            self::CAREER_PREPARATION => 'Persiapan karier',
            self::BOARDING_TRANSITION => 'Transisi asrama',
            self::EXAM_PREPARATION => 'Persiapan ujian',
            self::SOCIAL_SKILLS => 'Keterampilan sosial',
            self::CHARACTER_DEVELOPMENT => 'Pengembangan karakter',
            self::OTHER => 'Lainnya',
        };
    }

    /** Domain pengembangan yang sejalan dengan program ini. */
    public function developmentDomain(): DevelopmentDomain
    {
        return match ($this) {
            self::STUDY_SKILLS, self::EXAM_PREPARATION => DevelopmentDomain::ACADEMIC,
            self::LEADERSHIP => DevelopmentDomain::LEADERSHIP,
            self::CAREER_PREPARATION => DevelopmentDomain::CAREER,
            self::BOARDING_TRANSITION => DevelopmentDomain::INDEPENDENCE,
            self::SOCIAL_SKILLS => DevelopmentDomain::SOCIAL,
            self::CHARACTER_DEVELOPMENT => DevelopmentDomain::CHARACTER,
            self::OTHER => DevelopmentDomain::SOCIAL,
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
