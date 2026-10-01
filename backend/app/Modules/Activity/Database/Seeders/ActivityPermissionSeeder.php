<?php

namespace App\Modules\Activity\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Izin & peran Modul Student Activity (\§3, \§4\–8, \§88).
 *
 * Prinsip pembagiannya:
 *  - **ACTIVITY_ADMIN** mengurus katalog, periode, penilaian, dan sertifikat,
 *    tetapi **tidak** otomatis menyetujui pendaftaran siswa maupun memverifikasi
 *    prestasi \— dua hal yang menuntut penilaian manusia,
 *  - **ACTIVITY_MANAGER / COORDINATOR** menjalankan operasional program,
 *  - **COACH / MENTOR** hanya bekerja pada program yang ditugaskan (ditegakkan
 *    `ActivityAccessService`, bukan sekadar izin),
 *  - **JUDGE** hanya menilai penampilan yang ditugaskan, dan **tidak** boleh
 *    mengubah data peserta,
 *  - **FINANCE / INVENTORY / FACILITY** memegang sisi dananya/barangnya/ruangnya,
 *  - **STUDENT / PARENT** memakai portal: mendaftar, melihat, menyetujui.
 */
class ActivityPermissionSeeder extends Seeder
{
    /** @var list<string> */
    protected array $permissions = [
        'activity.view',
        'activity.portal.view',
        'activity.category.manage',
        'activity.period.manage',
        'activity.program.view',
        'activity.program.manage',
        'activity.program.publish',
        'activity.registration.view',
        'activity.registration.create',
        'activity.registration.decide',
        'activity.registration.cancel',
        'activity.registration.override',
        'activity.consent.view',
        'activity.consent.request',
        'activity.consent.decide',
        'activity.membership.view',
        'activity.membership.manage',
        'activity.schedule.manage',
        'activity.session.manage',
        'activity.attendance.view',
        'activity.attendance.record',
        'activity.attendance.override',
        'activity.assessment.view',
        'activity.assessment.manage',
        'activity.assessment.publish',
        'activity.competition.view',
        'activity.competition.manage',
        'activity.competition.judge',
        'activity.competition.score.correct',
        'activity.competition.result.publish',
        'activity.achievement.view',
        'activity.achievement.manage',
        'activity.achievement.verify',
        'activity.certificate.view',
        'activity.certificate.issue',
        'activity.certificate.revoke',
        'activity.portfolio.view',
        'activity.portfolio.share',
        'activity.finance.view',
        'activity.finance.manage',
        'activity.inventory.view',
        'activity.inventory.manage',
        'activity.facility.view',
        'activity.facility.manage',
        'activity.report.view',
        'activity.analytics.view',
        'activity.audit.view',
        'activity.counseling.view',
        'activity.settings.view',
    ];

    /**
     * Peran sistem \→ daftar izin.
     *
     * @var array<string, list<string>|string>
     */
    protected array $roles = [
        'ACTIVITY_ADMIN' => [
            'activity.view', 'activity.category.manage', 'activity.period.manage',
            'activity.program.view', 'activity.program.manage', 'activity.program.publish',
            'activity.registration.view', 'activity.registration.create', 'activity.consent.view',
            'activity.consent.request', 'activity.membership.view', 'activity.membership.manage',
            'activity.schedule.manage', 'activity.session.manage',
            'activity.attendance.view', 'activity.assessment.view', 'activity.assessment.manage',
            'activity.assessment.publish', 'activity.competition.view', 'activity.competition.manage',
            'activity.achievement.view', 'activity.achievement.manage', 'activity.certificate.view',
            'activity.certificate.issue', 'activity.certificate.revoke',
            'activity.portfolio.view', 'activity.portfolio.share',
            'activity.report.view', 'activity.analytics.view', 'activity.audit.view',
            'activity.finance.view', 'activity.inventory.view', 'activity.facility.view',
            'activity.settings.view',
            'activity.registration.override', 'activity.registration.cancel',
        ],
        'ACTIVITY_MANAGER' => [
            'activity.view', 'activity.program.view', 'activity.program.manage', 'activity.program.publish',
            'activity.registration.view', 'activity.registration.create', 'activity.registration.decide',
            'activity.registration.cancel', 'activity.registration.override',
            'activity.consent.view', 'activity.consent.request', 'activity.membership.view',
            'activity.membership.manage',
            'activity.schedule.manage', 'activity.session.manage', 'activity.attendance.view',
            'activity.attendance.record', 'activity.attendance.override',
            'activity.assessment.view', 'activity.assessment.manage',
            'activity.assessment.publish', 'activity.competition.view', 'activity.competition.manage',
            'activity.competition.result.publish', 'activity.competition.score.correct',
            'activity.achievement.view', 'activity.achievement.manage', 'activity.certificate.view', 'activity.portfolio.view',
            'activity.report.view', 'activity.analytics.view',
            // Permintaan ke modul lain perlu terlihat oleh pengelola aktivitas
            // (dialah yang mengajukan), sedangkan tindak lanjutnya tetap milik
            // modul Keuangan/Inventaris/Sarpras.
            'activity.finance.view', 'activity.inventory.view', 'activity.facility.view',
        ],
        'ACTIVITY_COORDINATOR' => [
            'activity.view', 'activity.program.view', 'activity.registration.view',
            'activity.registration.create', 'activity.registration.decide', 'activity.registration.cancel',
            'activity.consent.view', 'activity.membership.view',
            'activity.schedule.manage',
            'activity.session.manage', 'activity.attendance.view', 'activity.attendance.record',
            'activity.assessment.view', 'activity.assessment.manage', 'activity.achievement.view', 'activity.portfolio.view',
            'activity.achievement.manage', 'activity.portfolio.view', 'activity.report.view', 'activity.analytics.view',
        ],
        'ACTIVITY_ADVISOR' => [
            'activity.view', 'activity.program.view', 'activity.registration.view',
            'activity.registration.create', 'activity.membership.view', 'activity.consent.view',
            'activity.schedule.manage',
            'activity.session.manage', 'activity.attendance.view', 'activity.attendance.record',
            'activity.assessment.view', 'activity.assessment.manage', 'activity.achievement.view',
            'activity.achievement.manage',
        ],
        'COACH' => [
            'activity.view', 'activity.program.view', 'activity.membership.view',
            'activity.session.manage', 'activity.attendance.view', 'activity.attendance.record',
            'activity.assessment.view', 'activity.assessment.manage', 'activity.achievement.view',
        ],
        'MENTOR' => [
            'activity.view', 'activity.program.view', 'activity.membership.view',
            'activity.attendance.view', 'activity.assessment.view',
        ],
        'ACTIVITY_JUDGE' => [
            'activity.view', 'activity.competition.view', 'activity.competition.judge',
        ],
        'sinter' => [],
        'siswa' => ['activity.view', 'activity.portal.view', 'activity.registration.create', 'activity.portfolio.view', 'activity.portfolio.share'],
        'wali-murid' => ['activity.view', 'activity.portal.view', 'activity.consent.decide', 'activity.registration.create', 'activity.portfolio.view', 'activity.portfolio.share'],
        'guru' => ['activity.view', 'activity.attendance.view', 'activity.assessment.view', 'activity.achievement.manage', 'activity.registration.decide'],
        'wali-kelas' => ['activity.view', 'activity.attendance.view', 'activity.achievement.manage', 'activity.registration.create'],
        'kepala-sekolah' => [
            'activity.view', 'activity.program.view', 'activity.registration.view',
            'activity.registration.decide', 'activity.achievement.view', 'activity.achievement.verify',
            'activity.competition.view', 'activity.competition.result.publish',
            'activity.certificate.view', 'activity.portfolio.view', 'activity.report.view', 'activity.analytics.view', 'activity.audit.view',
            'activity.counseling.view',
        ],
        'admin-sekolah' => ['activity.view', 'activity.program.view', 'activity.program.manage', 'activity.registration.view', 'activity.membership.view', 'activity.portfolio.view', 'activity.report.view', 'activity.settings.view', 'activity.counseling.view'],
        'bendahara' => ['activity.view', 'activity.finance.view', 'activity.finance.manage'],
        'petugas-inventaris' => ['activity.view', 'activity.inventory.view', 'activity.inventory.manage'],
        'petugas-sarpras' => ['activity.view', 'activity.facility.view', 'activity.facility.manage'],
        'auditor' => ['activity.view', 'activity.report.view', 'activity.analytics.view', 'activity.audit.view'],
        'admin-klinik' => [],
        'ERP_ADMIN' => '*',
        'super-admin' => '*',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Peran baru khusus modul aktivitas.
        foreach (['ACTIVITY_ADMIN', 'ACTIVITY_MANAGER', 'ACTIVITY_COORDINATOR', 'ACTIVITY_ADVISOR', 'COACH', 'MENTOR', 'ACTIVITY_JUDGE'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        foreach ($this->roles as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');

            if ($permissions === '*') {
                // Peran super/ERP memang memegang seluruh izin. Modul ini
                // **menambahkan** izinnya — bukan menyinkronkan — karena
                // menyinkronkan akan menghapus izin modul lain yang sudah ada.
                $role->givePermissionTo($this->permissions);

                continue;
            }

            if ($permissions === []) {
                continue;
            }

            $role->givePermissionTo($this->permissionsFor($permissions));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Tambahkan izin modul ini ke peran yang sudah ada tanpa menghapus izin lain.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    protected function permissionsFor(array $permissions): array
    {
        return array_values(array_intersect($permissions, $this->permissions));
    }
}
