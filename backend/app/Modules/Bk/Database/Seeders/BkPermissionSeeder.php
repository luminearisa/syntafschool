<?php

namespace App\Modules\Bk\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Izin & peran Modul Bimbingan & Konseling.
 *
 * Dua sifat yang dijaga seeder ini:
 *
 * 1. **Aditif** — peran yang sudah ada (guru, wali kelas, kepala sekolah, dsb)
 *    hanya **ditambahi** izin; izin modul lain tidak pernah dihapus.
 * 2. **Tidak berlebihan** — guru/wali kelas/pengasuh tidak menerima izin
 *    catatan konseling, sehingga rahasia konseling tidak bergantung pada
 *    kedisiplinan tampilan.
 *
 * Peran baru khusus modul: `BK_ADMIN`, `BK_COORDINATOR`, `COUNSELOR`.
 */
class BkPermissionSeeder extends Seeder
{
    /** @var list<string> */
    public array $permissions = [
        'bk.view',
        'bk.manage',
        'bk.audit.view',
        'bk.support-profile.view',
        'bk.workload.view',
        'bk.confidential.view',
        'bk.referral.view',
        'bk.referral.create',
        'bk.referral.receive',
        'bk.case.view',
        'bk.case.view.all',
        'bk.case.create',
        'bk.case.update',
        'bk.case.assign',
        'bk.case.close',
        'bk.case.reopen',
        'bk.case.escalate',
        'bk.case.share',
        'bk.screening.view',
        'bk.screening.create',
        'bk.assessment.view',
        'bk.assessment.assign',
        'bk.assessment.submit',
        'bk.assessment.review',
        'bk.session.view',
        'bk.session.manage',
        'bk.plan.view',
        'bk.plan.manage',
        'bk.intervention.view',
        'bk.intervention.manage',
        'bk.follow-up.view',
        'bk.follow-up.manage',
        'bk.document.view',
        'bk.document.upload',
        'bk.note.view',
        'bk.note.create',
        'bk.note.view.confidential',
        'bk.meeting.view',
        'bk.meeting.manage',
        'bk.appointment.view',
        'bk.appointment.manage',
        'bk.report.view',
        'bk.report.export',
        'bk.portal.view',
        'bk.support-request.create',
        'bk.support-request.review',
        'bk.consent.view',
        'bk.consent.request',
        'bk.consent.decide',
        'bk.counselor.manage',
        'bk.tag.manage',
    ];

    /** @var array<string, list<string>|string> */
    public array $roles = [
        'BK_ADMIN' => '*',
        'BK_COORDINATOR' => [
            'bk.view',
            'bk.audit.view',
            'bk.support-profile.view',
            'bk.workload.view',
            'bk.confidential.view',
            'bk.referral.view',
            'bk.referral.create',
            'bk.referral.receive',
            'bk.case.view',
            'bk.case.view.all',
            'bk.case.create',
            'bk.case.update',
            'bk.case.assign',
            'bk.case.close',
            'bk.case.reopen',
            'bk.case.escalate',
            'bk.case.share',
            'bk.screening.view',
            'bk.screening.create',
            'bk.assessment.view',
            'bk.assessment.assign',
            'bk.assessment.submit',
            'bk.assessment.review',
            'bk.session.view',
            'bk.session.manage',
            'bk.plan.view',
            'bk.plan.manage',
            'bk.intervention.view',
            'bk.intervention.manage',
            'bk.follow-up.view',
            'bk.follow-up.manage',
            'bk.document.view',
            'bk.document.upload',
            'bk.note.view',
            'bk.note.create',
            'bk.note.view.confidential',
            'bk.meeting.view',
            'bk.meeting.manage',
            'bk.appointment.view',
            'bk.appointment.manage',
            'bk.report.view',
            'bk.report.export',
            'bk.portal.view',
            'bk.support-request.create',
            'bk.support-request.review',
            'bk.consent.view',
            'bk.consent.request',
            'bk.consent.decide',
            'bk.tag.manage',
        ],
        'COUNSELOR' => [
            'bk.view',
            'bk.support-profile.view',
            'bk.referral.view',
            'bk.referral.create',
            'bk.referral.receive',
            'bk.case.view',
            'bk.case.create',
            'bk.case.update',
            'bk.case.close',
            'bk.case.escalate',
            'bk.case.share',
            'bk.screening.view',
            'bk.screening.create',
            'bk.assessment.view',
            'bk.assessment.assign',
            'bk.assessment.review',
            'bk.assessment.submit',
            'bk.session.view',
            'bk.session.manage',
            'bk.plan.view',
            'bk.plan.manage',
            'bk.intervention.view',
            'bk.intervention.manage',
            'bk.follow-up.view',
            'bk.follow-up.manage',
            'bk.document.view',
            'bk.document.upload',
            'bk.note.view',
            'bk.note.create',
            'bk.note.view.confidential',
            'bk.meeting.view',
            'bk.meeting.manage',
            'bk.appointment.view',
            'bk.appointment.manage',
            'bk.report.view',
            'bk.portal.view',
            'bk.support-request.review',
            'bk.consent.view',
            'bk.consent.request',
            'bk.confidential.view',
        ],
        'super-admin' => '*',
        'admin-sekolah' => '*',
        'kepala-sekolah' => [
            'bk.view',
            'bk.case.view',
            'bk.referral.view',
            'bk.report.view',
            'bk.audit.view',
            'bk.workload.view',
            'bk.support-profile.view',
            'bk.case.escalate',
        ],
        'auditor' => [
            'bk.view',
            'bk.audit.view',
            'bk.report.view',
            'bk.case.view',
            'bk.referral.view',
            'bk.report.export',
        ],
        'wali-kelas' => [
            'bk.view',
            'bk.referral.create',
            'bk.referral.view',
            'bk.case.view',
            'bk.support-profile.view',
            'bk.portal.view',
            'bk.meeting.view',
        ],
        'guru' => [
            'bk.view',
            'bk.referral.create',
            'bk.referral.view',
            'bk.portal.view',
        ],
        'pengasuh-asrama' => [
            'bk.view',
            'bk.referral.create',
            'bk.referral.view',
            'bk.support-profile.view',
            'bk.portal.view',
        ],
        'BOARDING_STAFF' => [
            'bk.view',
            'bk.referral.create',
            'bk.referral.view',
            'bk.support-profile.view',
            'bk.portal.view',
        ],
        'petugas-kesehatan' => [
            'bk.view',
            'bk.referral.create',
            'bk.referral.view',
        ],
        'siswa' => [
            'bk.view',
            'bk.portal.view',
            'bk.support-request.create',
            'bk.referral.create',
        ],
        'wali-murid' => [
            'bk.view',
            'bk.portal.view',
            'bk.support-request.create',
            'bk.referral.create',
            'bk.consent.view',
            'bk.consent.decide',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (['BK_ADMIN', 'BK_COORDINATOR', 'COUNSELOR'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        foreach ($this->roles as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');

            if ($permissions === '*') {
                // Peran super/ERP memegang seluruh izin; modul ini hanya menambahkan.
                $role->givePermissionTo($this->permissions);

                continue;
            }

            $role->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
