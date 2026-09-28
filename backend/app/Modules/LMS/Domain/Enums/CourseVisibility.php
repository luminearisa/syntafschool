<?php

namespace App\Modules\LMS\Domain\Enums;

enum CourseVisibility: string
{
    case PUBLIC = 'PUBLIC';
    case PRIVATE = 'PRIVATE';
    case INSTITUTION = 'INSTITUTION';
    case ENROLLED_ONLY = 'ENROLLED_ONLY';

    public function label(): string
    {
        return match ($this) {
            self::PUBLIC => 'Publik',
            self::PRIVATE => 'Privat',
            self::INSTITUTION => 'Internal Institusi',
            self::ENROLLED_ONLY => 'Hanya Peserta Terdaftar',
        };
    }

    /** Terlihat di katalog publik/guest (§30). */
    public function isPubliclyVisible(): bool
    {
        return $this === self::PUBLIC;
    }

    /** Boleh diikuti sendiri oleh siswa tanpa campur tangan admin (§16). */
    public function isSelfEnrollable(): bool
    {
        return in_array($this, [self::PUBLIC, self::INSTITUTION], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
