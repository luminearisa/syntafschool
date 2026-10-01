<?php

namespace App\Modules\Asset\Domain\Enums;

enum CustodianType: string
{
    case EMPLOYEE = 'EMPLOYEE';
    case TEACHER = 'TEACHER';
    case STAFF = 'STAFF';
    case DEPARTMENT = 'DEPARTMENT';
    case UNIT = 'UNIT';
    case ROOM = 'ROOM';
    case STUDENT = 'STUDENT';

    public function label(): string
    {
        return match ($this) {
            self::EMPLOYEE => 'Pegawai',
            self::TEACHER => 'Guru',
            self::STAFF => 'Staf',
            self::DEPARTMENT => 'Unit kerja',
            self::UNIT => 'Unit',
            self::ROOM => 'Ruang',
            self::STUDENT => 'Siswa',
        };
    }
}
