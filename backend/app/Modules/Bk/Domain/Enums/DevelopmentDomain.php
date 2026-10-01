<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Domain pengembangan siswa.
 *
 * Domain ini tentang pertumbuhan, bukan masalah: rencana pengembangan boleh dibuat
 * untuk anak yang sama sekali tidak memiliki kasus.
 */
enum DevelopmentDomain: string
{
    case ACADEMIC = 'ACADEMIC';
    case CHARACTER = 'CHARACTER';
    case LEADERSHIP = 'LEADERSHIP';
    case SOCIAL = 'SOCIAL';
    case COMMUNICATION = 'COMMUNICATION';
    case CAREER = 'CAREER';
    case SPIRITUAL_DEVELOPMENT = 'SPIRITUAL_DEVELOPMENT';
    case EXTRACURRICULAR = 'EXTRACURRICULAR';
    case INDEPENDENCE = 'INDEPENDENCE';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::ACADEMIC => 'Akademik',
            self::CHARACTER => 'Karakter',
            self::LEADERSHIP => 'Kepemimpinan',
            self::SOCIAL => 'Sosial',
            self::COMMUNICATION => 'Komunikasi',
            self::CAREER => 'Karier',
            self::SPIRITUAL_DEVELOPMENT => 'Pengembangan spiritual',
            self::EXTRACURRICULAR => 'Ekstrakurikuler',
            self::INDEPENDENCE => 'Kemandirian',
        };
    }

    /** Domain yang biasanya melibatkan pihak di luar tim BK. */
    public function involvesExternalParty(): bool
    {
        return in_array($this, [self::CAREER, self::EXTRACURRICULAR], true);
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
