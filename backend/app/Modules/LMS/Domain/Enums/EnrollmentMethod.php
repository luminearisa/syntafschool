<?php

namespace App\Modules\LMS\Domain\Enums;

enum EnrollmentMethod: string
{
    case MANUAL = 'MANUAL';
    case ACADEMIC = 'ACADEMIC';
    case SELF = 'SELF';
    case INVITATION = 'INVITATION';
    case COHORT = 'COHORT';
    case GROUP = 'GROUP';
    case IMPORT = 'IMPORT';

    public function label(): string
    {
        return match ($this) {
            self::MANUAL => 'Manual',
            self::ACADEMIC => 'Sinkronisasi Akademik',
            self::SELF => 'Daftar Sendiri',
            self::INVITATION => 'Undangan',
            self::COHORT => 'Angkatan',
            self::GROUP => 'Kelompok',
            self::IMPORT => 'Impor',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
