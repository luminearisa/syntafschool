<?php

namespace App\Modules\LMS\Domain\Enums;

/** Jenis forum (§32). */
enum ForumType: string
{
    case GENERAL = 'GENERAL';
    case COURSE_ANNOUNCEMENT = 'COURSE_ANNOUNCEMENT';
    case Q_AND_A = 'Q_AND_A';
    case STANDARD = 'STANDARD';

    public function label(): string
    {
        return match ($this) {
            self::GENERAL => 'Forum umum',
            self::COURSE_ANNOUNCEMENT => 'Pengumuman course',
            self::Q_AND_A => 'Tanya jawab',
            self::STANDARD => 'Forum standar',
        };
    }

    /** Siswa boleh membuka diskusi baru? */
    public function allowsStudentDiscussion(): bool
    {
        return $this !== self::COURSE_ANNOUNCEMENT;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
