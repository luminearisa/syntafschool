<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Peran yang dikenali LMS (§3). Dipetakan dari role ERP (integrated) atau
 * kolom `role` pada `lms_users` (standalone) supaya Application layer tidak
 * pernah bergantung pada penamaan role milik ERP.
 */
enum ActorRole: string
{
    case ADMIN = 'ADMIN';
    case TEACHER = 'TEACHER';
    case STUDENT = 'STUDENT';
    case PARENT = 'PARENT';
    case GUEST = 'GUEST';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Administrator',
            self::TEACHER => 'Guru',
            self::STUDENT => 'Siswa',
            self::PARENT => 'Orang Tua',
            self::GUEST => 'Tamu',
        };
    }

    public function isLearner(): bool
    {
        return $this === self::STUDENT;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
