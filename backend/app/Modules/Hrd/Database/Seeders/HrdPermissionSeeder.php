<?php

namespace App\Modules\Hrd\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Hak akses Modul HRD (§53, §54).
 *
 * Yang dipisahkan di sini bukan "siapa boleh apa" secara umum, melainkan
 * pemisahan yang dituntut pemeriksaan:
 *
 *  - **mencatat ≠ menyetujui** — `hr.lifecycle.create` vs `hr.lifecycle.approve`;
 *  - **menyetujui ≠ menerapkan** — `hr.lifecycle.approve` vs `hr.lifecycle.execute`;
 *  - **mengelola ≠ mengaudit** — `hr.employee.update` vs `hr.audit.view`
 *    (auditor melihat tanpa dapat mengubah);
 *  - **data sensitif punya izin sendiri** — `hr.employee.sensitive.view`
 *    (NIK, nomor rekening) tidak otomatis menyertai `hr.employee.view`;
 *  - **HR bukan administrator teknis** — `hr.admin` hanya untuk pemasangan,
 *    bukan untuk membaca data kepegawaian.
 */
class HrdPermissionSeeder extends Seeder
{
    /** @var list<string> */
    public const PERMISSIONS = [
        'hr.admin',

        // Pegawai
        'hr.employee.view', 'hr.employee.view.all', 'hr.employee.create', 'hr.employee.update',
        'hr.employee.delete', 'hr.employee.archive', 'hr.employee.merge', 'hr.employee.import',
        'hr.employee.export', 'hr.employee.sensitive.view',

        // Organisasi & posisi
        'hr.organization.view', 'hr.organization.manage',
        'hr.position.view', 'hr.position.manage',

        // Kepegawaian & kontrak
        'hr.employment.view', 'hr.employment.manage',
        'hr.contract.view', 'hr.contract.manage',

        // Dokumen
        'hr.document.view', 'hr.document.upload', 'hr.document.verify', 'hr.document.delete',

        // Kompetensi
        'hr.skill.view', 'hr.skill.manage', 'hr.skill.verify',
        'hr.certification.view', 'hr.certification.manage', 'hr.certification.verify',

        // Siklus hidup
        'hr.lifecycle.view', 'hr.lifecycle.create', 'hr.lifecycle.approve', 'hr.lifecycle.execute',

        // Onboarding & offboarding
        'hr.onboarding.view', 'hr.onboarding.manage',
        'hr.offboarding.view', 'hr.offboarding.manage', 'hr.offboarding.clearance',

        // Permintaan HR
        'hr.request.view', 'hr.request.create', 'hr.request.approve', 'hr.request.manage',

        // Layanan mandiri & atasan
        'hr.self.view', 'hr.team.view',

        // Catatan
        'hr.note.create', 'hr.note.view.sensitive',

        // Laporan, kualitas data, audit, setelan
        'hr.report.view', 'hr.report.export',
        'hr.dashboard.view',
        'hr.data_quality.view', 'hr.data_quality.scan', 'hr.data_quality.manage',
        'hr.audit.view',
        'hr.settings.view', 'hr.settings.manage',
    ];

    /** @var array<string, list<string>> */
    public const ROLES = [
        'HR_ADMIN' => ['*'],
        'HR_MANAGER' => [
            'hr.employee.view', 'hr.employee.create', 'hr.employee.update', 'hr.employee.archive',
            'hr.employee.export', 'hr.employee.sensitive.view', 'hr.employee.import',
            'hr.organization.view', 'hr.position.view', 'hr.position.manage',
            'hr.employment.view', 'hr.employment.manage', 'hr.contract.view', 'hr.contract.manage',
            'hr.document.view', 'hr.document.upload', 'hr.document.verify',
            'hr.skill.view', 'hr.certification.view', 'hr.certification.verify',
            'hr.lifecycle.view', 'hr.lifecycle.create', 'hr.lifecycle.approve', 'hr.lifecycle.execute',
            'hr.onboarding.view', 'hr.onboarding.manage', 'hr.offboarding.view', 'hr.offboarding.manage',
            'hr.offboarding.clearance', 'hr.request.view', 'hr.request.approve', 'hr.request.manage',
            'hr.note.create', 'hr.note.view.sensitive', 'hr.report.view', 'hr.report.export',
            'hr.dashboard.view', 'hr.data_quality.view', 'hr.data_quality.scan', 'hr.audit.view',
            'hr.self.view', 'hr.team.view', 'hr.settings.view',
        ],
        'HR_OFFICER' => [
            'hr.employee.view', 'hr.employee.create', 'hr.employee.update', 'hr.employee.import',
            'hr.organization.view', 'hr.position.view', 'hr.employment.view', 'hr.employment.manage',
            'hr.contract.view', 'hr.contract.manage', 'hr.document.view', 'hr.document.upload',
            'hr.skill.view', 'hr.skill.manage', 'hr.certification.view', 'hr.certification.manage',
            'hr.lifecycle.view', 'hr.lifecycle.create', 'hr.onboarding.view', 'hr.onboarding.manage',
            'hr.offboarding.view', 'hr.offboarding.manage', 'hr.request.view', 'hr.request.manage',
            'hr.note.create', 'hr.report.view', 'hr.dashboard.view', 'hr.data_quality.view',
            'hr.self.view', 'hr.team.view',
        ],
        'HR_RECRUITER' => [
            'hr.employee.view', 'hr.employee.create', 'hr.position.view', 'hr.organization.view',
            'hr.lifecycle.view', 'hr.lifecycle.create', 'hr.onboarding.view', 'hr.onboarding.manage',
            'hr.report.view', 'hr.dashboard.view', 'hr.self.view',
        ],
        'HR_DATA_ADMIN' => [
            'hr.employee.view', 'hr.employee.create', 'hr.employee.update', 'hr.employee.import',
            'hr.employee.export', 'hr.organization.view', 'hr.organization.manage', 'hr.position.view',
            'hr.position.manage', 'hr.employment.view', 'hr.employment.manage', 'hr.document.view',
            'hr.document.upload', 'hr.data_quality.view', 'hr.data_quality.scan', 'hr.data_quality.manage',
            'hr.settings.view', 'hr.settings.manage', 'hr.report.view', 'hr.dashboard.view', 'hr.self.view',
        ],
        'HR_AUDITOR' => [
            'hr.employee.view', 'hr.employee.view.all', 'hr.organization.view', 'hr.position.view',
            'hr.employment.view', 'hr.contract.view', 'hr.document.view', 'hr.lifecycle.view',
            'hr.onboarding.view', 'hr.offboarding.view', 'hr.request.view', 'hr.report.view',
            'hr.report.export', 'hr.dashboard.view', 'hr.data_quality.view', 'hr.audit.view',
        ],
        'DOCUMENT_VERIFIER' => [
            'hr.employee.view', 'hr.document.view', 'hr.document.verify', 'hr.certification.view',
            'hr.certification.verify', 'hr.skill.view', 'hr.skill.verify', 'hr.self.view',
        ],
        'HR_APPROVER' => [
            'hr.employee.view', 'hr.lifecycle.view', 'hr.lifecycle.approve', 'hr.request.view',
            'hr.request.approve', 'hr.team.view', 'hr.self.view', 'hr.dashboard.view',
        ],
        'HR_VIEWER' => ['hr.employee.view', 'hr.organization.view', 'hr.position.view', 'hr.report.view', 'hr.self.view'],
        'ORG_ADMIN' => [
            'hr.employee.view', 'hr.organization.view', 'hr.organization.manage', 'hr.position.view',
            'hr.position.manage', 'hr.employment.view', 'hr.report.view', 'hr.dashboard.view',
            'hr.team.view', 'hr.self.view',
        ],
        'DEPARTMENT_MANAGER' => [
            'hr.employee.view', 'hr.team.view', 'hr.contract.view', 'hr.document.view', 'hr.lifecycle.view',
            'hr.lifecycle.create', 'hr.request.view', 'hr.request.approve', 'hr.self.view',
            'hr.onboarding.view', 'hr.offboarding.view', 'hr.dashboard.view',
        ],
        'LINE_MANAGER' => [
            'hr.employee.view', 'hr.team.view', 'hr.lifecycle.view', 'hr.lifecycle.create',
            'hr.request.view', 'hr.request.approve', 'hr.self.view',
        ],
        'EMPLOYEE' => ['hr.self.view'],
    ];

    /**
     * Peran pegawai yang sudah ada di ERP ini juga mendapat hak layanan mandiri,
     * sehingga guru/staf langsung dapat membuka "Profil Saya" tanpa admin harus
     * mengatur ulang peran mereka.
     *
     * @var list<string>
     */
    public const EXISTING_EMPLOYEE_ROLES = [
        'guru', 'wali-kelas', 'staf', 'kepala-sekolah', 'kepala-unit', 'kepala-asrama',
        'musyrif', 'wali-kamar', 'pustakawan', 'petugas-absensi', 'petugas-kedisiplinan',
        'petugas-kesehatan', 'petugas-perizinan', 'admin-boarding', 'admin-sekolah',
    ];

    /** @var list<string> */
    public const EXISTING_EMPLOYEE_GRANTS = ['hr.self.view', 'hr.team.view', 'hr.request.create'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (self::ROLES as $role => $permissions) {
            $model = Role::findOrCreate($role, 'web');
            $model->givePermissionTo($permissions === ['*'] ? self::PERMISSIONS : $permissions);
        }

        foreach (self::EXISTING_EMPLOYEE_ROLES as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

            $role?->givePermissionTo(self::EXISTING_EMPLOYEE_GRANTS);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
