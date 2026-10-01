<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Jenis cakupan pada direktori organisasi engine (`workflow_org_assignments`).
 *
 * Tabel ini **bukan** pengganti struktur organisasi HRD: ia hanya memetakan
 * cakupan yang sudah dikenal ERP (unit kerja, jabatan, cost center, kampus)
 * ke seorang pengguna yang berwenang. Bila sekolah sudah punya bagan organisasi
 * sendiri, ikat `OrganizationalDirectoryInterface` ke implementasi lain dan
 * tabel ini tidak lagi dipakai (§22, §26, §27).
 */
enum OrgScopeType: string
{
    case DEPARTMENT = 'DEPARTMENT';
    case POSITION = 'POSITION';
    case COST_CENTER = 'COST_CENTER';
    case CAMPUS = 'CAMPUS';
    case TEAM = 'TEAM';
    case ACADEMIC_PROGRAM = 'ACADEMIC_PROGRAM';

    public function label(): string
    {
        return match ($this) {
            self::DEPARTMENT => 'Unit / Departemen',
            self::POSITION => 'Jabatan',
            self::COST_CENTER => 'Cost Center',
            self::CAMPUS => 'Kampus / Sekolah',
            self::TEAM => 'Tim',
            self::ACADEMIC_PROGRAM => 'Program Akademik',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
