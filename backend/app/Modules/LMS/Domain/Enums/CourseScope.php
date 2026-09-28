<?php

namespace App\Modules\LMS\Domain\Enums;

/** Cakupan daftar course yang diminta pemanggil (§37 GET /lms/courses). */
enum CourseScope: string
{
    case MANAGED = 'MANAGED';     // course yang boleh dikelola pemanggil (guru/admin)
    case ENROLLED = 'ENROLLED';   // course yang diikuti pemanggil (siswa)
    case PUBLIC = 'PUBLIC';       // katalog publik (guest)
    case ALL = 'ALL';             // seluruh course (admin)

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
